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
            ['First Name' => 'Ana', 'Middle Name' => '', 'Family Name' => 'Cruz', 'Sex' => 'Female', 'Student ID Number' => '2026-001', 'Email' => 'ana@students.test'],
            ['First Name' => '', 'Middle Name' => '', 'Family Name' => 'NoFirstName', 'Sex' => '', 'Student ID Number' => '2026-002', 'Email' => 'noname@students.test'],
            ['First Name' => 'Bad', 'Middle Name' => '', 'Family Name' => 'Email', 'Sex' => 'Male', 'Student ID Number' => '2026-003', 'Email' => 'not-an-email'],
            ['First Name' => 'Already', 'Middle Name' => '', 'Family Name' => 'Exists', 'Sex' => 'Male', 'Student ID Number' => 'EXIST-001', 'Email' => 'already@students.test'],
            ['First Name' => 'Dup1', 'Middle Name' => '', 'Family Name' => 'One', 'Sex' => 'Male', 'Student ID Number' => 'DUPE-1', 'Email' => 'dup1@students.test'],
            ['First Name' => 'Dup2', 'Middle Name' => '', 'Family Name' => 'Two', 'Sex' => 'Female', 'Student ID Number' => 'DUPE-1', 'Email' => 'dup2@students.test'],
            ['First Name' => 'Weird', 'Middle Name' => '', 'Family Name' => 'Sex', 'Sex' => 'Other', 'Student ID Number' => '2026-007', 'Email' => 'weird@students.test'],
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
            ['First Name' => 'Ana', 'Middle Name' => 'Reyes', 'Family Name' => 'Cruz', 'Sex' => 'Female', 'Student ID Number' => '2026-001', 'Email' => 'ana@students.test'],
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
        $this->assertSame('ana@students.test', $student->email);
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
            ['First Name' => 'Good', 'Middle Name' => '', 'Family Name' => 'Row', 'Sex' => 'Male', 'Student ID Number' => '2026-010', 'Email' => 'good@students.test'],
            ['First Name' => '', 'Middle Name' => '', 'Family Name' => 'Bad', 'Sex' => '', 'Student ID Number' => '2026-011', 'Email' => 'bad@students.test'],
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
            $rows[] = ['First Name' => "S{$i}", 'Middle Name' => '', 'Family Name' => 'Test', 'Sex' => 'Male', 'Student ID Number' => "2026-{$i}", 'Email' => "s{$i}@students.test"];
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

    /**
     * Re-uploading a roster (after a dropped connection, or a file that
     * overlaps an earlier one) is routine. A student already imported under the
     * same ID AND email is reported as `existing` — skipped, but not an error
     * to fix. It used to come back as a red "already in use", which read as a
     * problem with the file when there was nothing to do.
     */
    public function test_reuploading_the_same_file_flags_already_created_rows_instead_of_duplicating(): void
    {
        Notification::fake();

        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        $rowData = [['First Name' => 'Once', 'Middle Name' => '', 'Family Name' => 'Only', 'Sex' => 'Male', 'Student ID Number' => '2026-020', 'Email' => 'once@students.test']];

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
        $this->assertSame(0, $second->json('invalid_count'));
        $this->assertSame(1, $second->json('existing_count'));
        $this->assertSame('existing', $second->json('rows.0.status'));
        $this->assertSame([], $second->json('rows.0.errors'));

        // Confirming the same file creates nothing and says why.
        $third = $this->post('/api/coordinator/accounts/bulk-import/confirm', [
            'file' => $this->csvUpload($rowData),
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ]);

        $third->assertOk();
        $this->assertSame(0, $third->json('created_count'));
        $this->assertSame('already_exists', $third->json('results.0.outcome'));
        $this->assertNull($third->json('results.0.temporary_password'));
        $this->assertSame(1, User::where('student_id_number', '2026-020')->count());
    }

    /**
     * The calm `existing` status needs BOTH halves to match. An ID held by an
     * account with a different email, or an email held under a different ID,
     * is a genuine clash and stays an error.
     */
    public function test_an_id_or_email_held_by_someone_else_is_still_an_error(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        User::factory()->create(['role' => 'student', 'student_id_number' => '2026-030', 'username' => '2026-030', 'email' => 'owner@students.test']);
        User::factory()->create(['role' => 'student', 'student_id_number' => '2026-031', 'username' => '2026-031', 'email' => 'other@students.test']);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->post('/api/coordinator/accounts/bulk-import/preview', [
            'file' => $this->csvUpload([
                // Same ID as the first account, different email.
                ['First Name' => 'Ana', 'Middle Name' => '', 'Family Name' => 'Cruz', 'Sex' => 'Female', 'Student ID Number' => '2026-030', 'Email' => 'someone.else@students.test'],
                // Same email as the second account (in another case), different ID.
                ['First Name' => 'Ben', 'Middle Name' => '', 'Family Name' => 'Reyes', 'Sex' => 'Male', 'Student ID Number' => '2026-099', 'Email' => 'OTHER@students.test'],
            ]),
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('invalid_count'));
        $this->assertSame(0, $response->json('existing_count'));
        $this->assertContains('This Student ID Number is already in use.', $response->json('rows.0.errors'));
        $this->assertContains('This Email is already in use.', $response->json('rows.1.errors'));

        // Both halves matching the first account (email in another case) is
        // the same student, already imported.
        $same = $this->post('/api/coordinator/accounts/bulk-import/preview', [
            'file' => $this->csvUpload([
                ['First Name' => 'Cara', 'Middle Name' => '', 'Family Name' => 'Diaz', 'Sex' => 'Female', 'Student ID Number' => '2026-030', 'Email' => 'Owner@Students.test'],
            ]),
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ]);

        $this->assertSame('existing', $same->json('rows.0.status'));
        $this->assertSame(1, $same->json('existing_count'));
    }

    /**
     * An account holding the ID and email but in another ROLE is not "this
     * student, already imported" — it is a clash.
     */
    public function test_a_matching_non_student_account_is_a_clash_not_an_existing_student(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        User::factory()->create(['role' => 'supervisor', 'username' => '2026-050', 'email' => 'sup50@students.test']);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->post('/api/coordinator/accounts/bulk-import/preview', [
            'file' => $this->csvUpload([
                ['First Name' => 'Ana', 'Middle Name' => '', 'Family Name' => 'Cruz', 'Sex' => 'Female', 'Student ID Number' => '2026-050', 'Email' => 'sup50@students.test'],
            ]),
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ]);

        $this->assertSame('invalid', $response->json('rows.0.status'));
        $this->assertContains('This Student ID Number is already in use.', $response->json('rows.0.errors'));
        $this->assertContains('This Email is already in use.', $response->json('rows.0.errors'));
    }

    /**
     * The fix-up download hands the coordinator back what they typed, so a
     * rejected "Other" in the Sex column must survive alongside the normalised
     * value the server uses.
     */
    public function test_preview_rows_carry_the_sex_value_as_typed(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->post('/api/coordinator/accounts/bulk-import/preview', [
            'file' => $this->csvUpload([
                ['First Name' => 'Ana', 'Middle Name' => '', 'Family Name' => 'Cruz', 'Sex' => 'Other', 'Student ID Number' => '2026-040', 'Email' => 'ana40@students.test'],
                ['First Name' => 'Ben', 'Middle Name' => '', 'Family Name' => 'Reyes', 'Sex' => 'm', 'Student ID Number' => '2026-041', 'Email' => 'ben41@students.test'],
            ]),
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ]);

        $this->assertSame('Other', $response->json('rows.0.sex_input'));
        $this->assertNull($response->json('rows.0.sex'));
        $this->assertSame('m', $response->json('rows.1.sex_input'));
        $this->assertSame('male', $response->json('rows.1.sex'));
        $this->assertSame('ready', $response->json('rows.1.status'));
    }

    public function test_coordinator_cannot_bulk_import_into_another_departments_batch(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $foreignBatch = $this->batchFor($bsba, $this->coordinatorFor($bsba));

        Sanctum::actingAs($coordinator, ['*']);

        $this->post('/api/coordinator/accounts/bulk-import/preview', [
            'file' => $this->csvUpload([['First Name' => 'X', 'Middle Name' => '', 'Family Name' => 'Y', 'Sex' => 'Male', 'Student ID Number' => '2026-030', 'Email' => 'x@students.test']]),
            'program_id' => $bsba->id,
            'batch_id' => $foreignBatch->id,
        ])->assertStatus(422)->assertJsonValidationErrors('batch_id');
    }

    /**
     * A colleague's batch in the SAME department is allowed — the scope every
     * other placement path (info-sheet Accept, the batch roster) already used.
     * Bulk import was the odd one out: a coordinator covering for an absent
     * colleague could Accept and roster students into that colleague's batch,
     * but not create their accounts.
     */
    public function test_coordinator_can_bulk_import_into_a_department_colleagues_batch(): void
    {
        Notification::fake();
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $colleaguesBatch = $this->batchFor($bsit, $this->coordinatorFor($bsit));

        Sanctum::actingAs($coordinator, ['*']);

        $this->post('/api/coordinator/accounts/bulk-import/confirm', [
            'file' => $this->csvUpload([['First Name' => 'X', 'Middle Name' => '', 'Family Name' => 'Y', 'Sex' => 'Male', 'Student ID Number' => '2026-031', 'Email' => 'x31@students.test']]),
            'program_id' => $bsit->id,
            'batch_id' => $colleaguesBatch->id,
        ])->assertOk()->assertJsonPath('created_count', 1);

        $this->assertSame(
            $colleaguesBatch->id,
            User::where('student_id_number', '2026-031')->firstOrFail()->studentInformationSheets()->value('batch_id')
        );
    }

    /**
     * The SPA walks a file slice by slice so no request outlives the proxy's
     * 120s limit. The whole file is still validated on each call (a duplicate
     * ID across two slices is still caught), but only the slice is created.
     */
    public function test_confirm_creates_only_the_requested_slice_and_reports_where_to_continue(): void
    {
        Notification::fake();
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        Sanctum::actingAs($coordinator, ['*']);

        $rows = [];
        foreach (range(1, 5) as $i) {
            $rows[] = ['First Name' => "S{$i}", 'Middle Name' => '', 'Family Name' => 'Slice', 'Sex' => 'Male', 'Student ID Number' => "2026-50{$i}", 'Email' => "s{$i}@students.test"];
        }
        $send = fn (int $offset) => $this->post('/api/coordinator/accounts/bulk-import/confirm', [
            'file' => $this->csvUpload($rows),
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
            'offset' => $offset,
            'limit' => 2,
        ], ['Accept' => 'application/json']);

        $first = $send(0)->assertOk();
        $this->assertSame(2, $first->json('created_count'));
        $this->assertSame(5, $first->json('total_rows'));
        $this->assertSame(2, $first->json('next_offset'));
        $this->assertSame(['2026-501', '2026-502'], array_column($first->json('results'), 'student_id_number'));
        $this->assertSame(2, User::where('student_id_number', 'like', '2026-50%')->count());

        $send(2)->assertOk()->assertJsonPath('next_offset', 4);

        $last = $send(4)->assertOk();
        $this->assertNull($last->json('next_offset'));
        $this->assertSame(['2026-505'], array_column($last->json('results'), 'student_id_number'));
        $this->assertSame(5, User::where('student_id_number', 'like', '2026-50%')->count());
    }

    /** Letters and digits only — the password is read out and typed on a phone. */
    public function test_temporary_passwords_carry_no_symbols(): void
    {
        Notification::fake();
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        Sanctum::actingAs($coordinator, ['*']);

        $rows = [];
        foreach (range(1, 8) as $i) {
            $rows[] = ['First Name' => "P{$i}", 'Middle Name' => '', 'Family Name' => 'Pw', 'Sex' => 'Male', 'Student ID Number' => "2026-60{$i}", 'Email' => "p{$i}@students.test"];
        }

        $results = $this->post('/api/coordinator/accounts/bulk-import/confirm', [
            'file' => $this->csvUpload($rows),
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ], ['Accept' => 'application/json'])->assertOk()->json('results');

        foreach ($results as $row) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/', $row['temporary_password']);
        }
    }

    /**
     * Values the database cannot hold are refused at PREVIEW with a reason.
     * They used to preview "Ready" and then fail at confirm on MySQL with
     * "Could not be created — please retry", which no retry could fix.
     */
    public function test_preview_refuses_values_longer_than_their_columns(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        Sanctum::actingAs($coordinator, ['*']);

        $rows = $this->post('/api/coordinator/accounts/bulk-import/preview', [
            'file' => $this->csvUpload([
                ['First Name' => 'Ana', 'Middle Name' => '', 'Family Name' => 'Cruz', 'Sex' => '', 'Student ID Number' => str_repeat('9', 31), 'Email' => 'long.id@students.test'],
                ['First Name' => str_repeat('N', 80), 'Middle Name' => str_repeat('M', 40), 'Family Name' => str_repeat('F', 40), 'Sex' => '', 'Student ID Number' => '2026-701', 'Email' => 'long.name@students.test'],
                ['First Name' => str_repeat('N', 101), 'Middle Name' => '', 'Family Name' => 'Cruz', 'Sex' => '', 'Student ID Number' => '2026-702', 'Email' => 'long.part@students.test'],
            ]),
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ], ['Accept' => 'application/json'])->assertOk()->json('rows');

        $this->assertFalse($rows[0]['valid']);
        $this->assertStringContainsString('Student ID Number may not be longer than 30', $rows[0]['errors'][0]);
        $this->assertFalse($rows[1]['valid']);
        $this->assertStringContainsString('full name may not be longer than 150', implode(' ', $rows[1]['errors']));
        $this->assertFalse($rows[2]['valid']);
        $this->assertStringContainsString('First Name may not be longer than 100', implode(' ', $rows[2]['errors']));
    }

    /**
     * The template ships with a sample row (Juan Dela Cruz,
     * juan.delacruz@example.com). Left in by mistake it created a real
     * account and consumed the sample ID; example.com cannot receive mail, so
     * any address there is refused.
     */
    public function test_the_templates_placeholder_address_is_refused(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        Sanctum::actingAs($coordinator, ['*']);

        $rows = $this->post('/api/coordinator/accounts/bulk-import/preview', [
            'file' => $this->csvUpload([
                ['First Name' => 'Juan', 'Middle Name' => 'Santos', 'Family Name' => 'Dela Cruz', 'Sex' => 'Male', 'Student ID Number' => '2026-00123', 'Email' => 'juan.delacruz@example.com'],
                ['First Name' => 'Ana', 'Middle Name' => '', 'Family Name' => 'Cruz', 'Sex' => 'Female', 'Student ID Number' => '2026-801', 'Email' => 'ana@mail.example.org'],
                ['First Name' => 'Ben', 'Middle Name' => '', 'Family Name' => 'Lim', 'Sex' => 'Male', 'Student ID Number' => '2026-802', 'Email' => 'ben@notexample.com'],
            ]),
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ], ['Accept' => 'application/json'])->assertOk()->json('rows');

        $this->assertFalse($rows[0]['valid']);
        $this->assertStringContainsString('placeholder address', $rows[0]['errors'][0]);
        $this->assertFalse($rows[1]['valid'], 'a subdomain of example.org is just as undeliverable');
        $this->assertTrue($rows[2]['valid'], 'a real domain that merely contains the word is fine');
    }

    // Reissuing a bulk-imported student's credentials moved off this page and
    // into the Credential Manager (profile popover) on 2026-09-08; its coverage
    // lives in CredentialManagerTest.
}
