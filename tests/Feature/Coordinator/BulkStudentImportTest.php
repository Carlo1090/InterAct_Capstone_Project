<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use App\Notifications\NewAccountCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BulkStudentImportTest extends TestCase
{
    use RefreshDatabase;

    private function programFor(string $code, string $deptCode = 'CAST'): Program
    {
        $department = Department::firstOrCreate(
            ['code' => $deptCode],
            ['name' => $deptCode.' Department', 'is_active' => true]
        );

        return Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => $code],
            ['name' => $code.' Program', 'is_active' => true]
        );
    }

    private function coordinatorFor(Program $program): User
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($program->department_id);

        return $coordinator;
    }

    private function batchFor(Program $program, User $coordinator): Batch
    {
        return Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => $program->code.' 2026 Internship',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<array<string, string>>  $rows  each row keyed by header text
     */
    private function csvUpload(array $rows, string $filename = 'roster.csv'): UploadedFile
    {
        $headers = ['First Name', 'Middle Name', 'Family Name', 'Sex', 'Student ID Number', 'Email'];

        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                fn (string $header) => '"'.str_replace('"', '""', $row[$header] ?? '').'"',
                $headers
            ));
        }

        $path = tempnam(sys_get_temp_dir(), 'roster').'.csv';
        file_put_contents($path, implode("\r\n", $lines));

        return new UploadedFile($path, $filename, 'text/csv', null, true);
    }

    public function test_preview_flags_valid_and_invalid_rows_without_creating_anything(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        User::factory()->create(['student_id_number' => 'EXIST-001']);

        $file = $this->csvUpload([
            ['First Name' => 'Ana', 'Middle Name' => '', 'Family Name' => 'Cruz', 'Sex' => 'Female', 'Student ID Number' => '2026-001', 'Email' => 'ana@example.com'],
            ['First Name' => '', 'Middle Name' => '', 'Family Name' => 'NoFirstName', 'Sex' => '', 'Student ID Number' => '2026-002', 'Email' => 'noname@example.com'],
            ['First Name' => 'Bad', 'Middle Name' => '', 'Family Name' => 'Email', 'Sex' => 'Male', 'Student ID Number' => '2026-003', 'Email' => 'not-an-email'],
            ['First Name' => 'Already', 'Middle Name' => '', 'Family Name' => 'Exists', 'Sex' => 'Male', 'Student ID Number' => 'EXIST-001', 'Email' => 'already@example.com'],
            ['First Name' => 'Dup1', 'Middle Name' => '', 'Family Name' => 'One', 'Sex' => 'Male', 'Student ID Number' => 'DUPE-1', 'Email' => 'dup1@example.com'],
            ['First Name' => 'Dup2', 'Middle Name' => '', 'Family Name' => 'Two', 'Sex' => 'Female', 'Student ID Number' => 'DUPE-1', 'Email' => 'dup2@example.com'],
            ['First Name' => 'Weird', 'Middle Name' => '', 'Family Name' => 'Sex', 'Sex' => 'Other', 'Student ID Number' => '2026-007', 'Email' => 'weird@example.com'],
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->post('/api/coordinator/accounts/bulk-import/preview', [
            'file' => $file,
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ]);

        $response->assertOk();
        $rows = $response->json('rows');

        $this->assertSame(1, $response->json('valid_count'));
        $this->assertSame(6, $response->json('invalid_count'));

        $this->assertTrue($rows[0]['valid']);
        $this->assertFalse($rows[1]['valid']);
        $this->assertStringContainsString('First Name', $rows[1]['errors'][0]);
        $this->assertFalse($rows[2]['valid']);
        $this->assertStringContainsString('Email format', $rows[2]['errors'][0]);
        $this->assertFalse($rows[3]['valid']);
        $this->assertStringContainsString('already in use', $rows[3]['errors'][0]);
        $this->assertFalse($rows[4]['valid']);
        $this->assertStringContainsString('more than once', $rows[4]['errors'][0]);
        $this->assertFalse($rows[5]['valid']);
        $this->assertStringContainsString('more than once', $rows[5]['errors'][0]);
        $this->assertFalse($rows[6]['valid']);
        $this->assertStringContainsString('Male or Female', $rows[6]['errors'][0]);

        // Preview never persists anything — only the coordinator and the
        // pre-seeded EXIST-001 user exist, no rows from the file.
        $this->assertSame(2, User::count());
    }

    public function test_confirm_creates_accounts_with_id_number_as_username_and_forces_password_change(): void
    {
        Notification::fake();

        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $file = $this->csvUpload([
            ['First Name' => 'Ana', 'Middle Name' => 'Reyes', 'Family Name' => 'Cruz', 'Sex' => 'Female', 'Student ID Number' => '2026-001', 'Email' => 'ana@example.com'],
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->post('/api/coordinator/accounts/bulk-import/confirm', [
            'file' => $file,
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('created_count'));

        $student = User::where('student_id_number', '2026-001')->first();
        $this->assertNotNull($student);
        $this->assertSame('2026-001', $student->username);
        $this->assertSame('ana@example.com', $student->email);
        $this->assertSame('Ana Reyes Cruz', $student->name);
        $this->assertTrue($student->must_change_password);
        $this->assertSame('student', $student->role);

        $this->assertSame('Female', ucfirst($student->studentProfile->sex));
        $this->assertSame('Reyes', $student->studentProfile->middle_name);

        $sheet = StudentInformationSheet::where('student_id', $student->id)->first();
        $this->assertSame('draft', $sheet->submission_status);
        $this->assertSame($batch->id, $sheet->batch_id);
        $this->assertSame('female', $sheet->personal_info['sex']);

        // Not yet enrolled — matches the manual create-account flow exactly.
        $this->assertDatabaseMissing('batch_students', ['student_id' => $student->id]);

        $outcome = $response->json('results')[0];
        $this->assertSame('created_and_emailed', $outcome['outcome']);
        $this->assertNotEmpty($outcome['temporary_password']);

        Notification::assertSentTo($student, NewAccountCredentials::class);
    }

    public function test_confirm_skips_invalid_rows_and_creates_only_the_valid_ones(): void
    {
        Notification::fake();

        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $file = $this->csvUpload([
            ['First Name' => 'Good', 'Middle Name' => '', 'Family Name' => 'Row', 'Sex' => 'Male', 'Student ID Number' => '2026-010', 'Email' => 'good@example.com'],
            ['First Name' => '', 'Middle Name' => '', 'Family Name' => 'Bad', 'Sex' => '', 'Student ID Number' => '2026-011', 'Email' => 'bad@example.com'],
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->post('/api/coordinator/accounts/bulk-import/confirm', [
            'file' => $file,
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('created_count'));
        $this->assertNotNull(User::where('student_id_number', '2026-010')->first());
        $this->assertNull(User::where('student_id_number', '2026-011')->first());

        $results = collect($response->json('results'));
        $this->assertSame('created_and_emailed', $results->firstWhere('student_id_number', '2026-010')['outcome']);
        $this->assertSame('skipped_invalid', $results->firstWhere('student_id_number', '2026-011')['outcome']);
    }

    public function test_confirm_rejects_a_file_over_the_row_cap(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $rows = [];
        for ($i = 1; $i <= 101; $i++) {
            $rows[] = ['First Name' => "S{$i}", 'Middle Name' => '', 'Family Name' => 'Test', 'Sex' => 'Male', 'Student ID Number' => "2026-{$i}", 'Email' => "s{$i}@example.com"];
        }
        $file = $this->csvUpload($rows);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->post('/api/coordinator/accounts/bulk-import/confirm', [
            'file' => $file,
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ]);

        $response->assertStatus(422);
        // Only the coordinator exists — nothing from the rejected file was created.
        $this->assertSame(1, User::count());
    }

    public function test_reuploading_the_same_file_flags_already_created_rows_instead_of_duplicating(): void
    {
        Notification::fake();

        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        $rowData = [['First Name' => 'Once', 'Middle Name' => '', 'Family Name' => 'Only', 'Sex' => 'Male', 'Student ID Number' => '2026-020', 'Email' => 'once@example.com']];

        Sanctum::actingAs($coordinator, ['*']);

        $this->post('/api/coordinator/accounts/bulk-import/confirm', [
            'file' => $this->csvUpload($rowData),
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ])->assertOk();

        $this->assertSame(1, User::where('student_id_number', '2026-020')->count());

        // Re-upload the identical file.
        $second = $this->post('/api/coordinator/accounts/bulk-import/preview', [
            'file' => $this->csvUpload($rowData),
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ]);

        $second->assertOk();
        $this->assertSame(0, $second->json('valid_count'));
        $this->assertStringContainsString('already in use', $second->json('rows')[0]['errors'][0]);
        $this->assertSame(1, User::where('student_id_number', '2026-020')->count());
    }

    public function test_coordinator_cannot_bulk_import_into_a_batch_they_do_not_coordinate(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);

        $otherCoordinator = $this->coordinatorFor($bsit);
        $foreignBatch = $this->batchFor($bsit, $otherCoordinator);

        Sanctum::actingAs($coordinator, ['*']);

        $this->post('/api/coordinator/accounts/bulk-import/preview', [
            'file' => $this->csvUpload([['First Name' => 'X', 'Middle Name' => '', 'Family Name' => 'Y', 'Sex' => 'Male', 'Student ID Number' => '2026-030', 'Email' => 'x@example.com']]),
            'program_id' => $bsit->id,
            'batch_id' => $foreignBatch->id,
        ])->assertStatus(422)->assertJsonValidationErrors('batch_id');
    }

    // Reissuing a bulk-imported student's credentials moved off this page and
    // into the Credential Manager (profile popover) on 2026-09-08; its coverage
    // lives in CredentialManagerTest.
}
