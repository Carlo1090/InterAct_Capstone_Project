<?php

namespace Tests\Feature\Student;

use App\Models\Batch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class InfoSheetGateTest extends TestCase
{
    use RefreshDatabase;
    use EnrollsStudentInBatch;

    /**
     * @return array{0: User, 1: Batch, 2: StudentInformationSheet}
     */
    private function studentWithSheet(string $status = 'draft'): array
    {
        $department = Department::firstOrCreate(['code' => 'CAST'], ['name' => 'CAST', 'is_active' => true]);
        $program = Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => 'BSIT'],
            ['name' => 'BS Information Technology', 'is_active' => true]
        );
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'BSIT 2026 '.uniqid(),
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
        ]);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $sheet = StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'submission_status' => $status,
            'personal_info' => ['last_name' => 'Cruz', 'first_name' => 'Ana'],
            'academic_info' => [],
            'ojt_info' => [],
            'emergency_contact' => null,
        ]);

        return [$student, $batch, $sheet];
    }

    public function test_gated_student_cannot_reach_other_student_endpoints(): void
    {
        [$student] = $this->studentWithSheet('draft');
        Sanctum::actingAs($student, ['*']);

        $this->getJson('/api/student/journal-entries')->assertStatus(403);
        $this->getJson('/api/student/weekly-logs')->assertStatus(403);
        $this->getJson('/api/student/journal-calendar')->assertStatus(403);
    }

    public function test_gated_student_can_reach_info_sheet_and_companies(): void
    {
        [$student] = $this->studentWithSheet('draft');
        Company::create(['name' => 'Curated Co', 'address' => 'Tagbilaran', 'is_active' => true]);
        Sanctum::actingAs($student, ['*']);

        $this->getJson('/api/student/info-sheet')->assertOk();
        $this->getJson('/api/student/companies')->assertOk()->assertJsonFragment(['name' => 'Curated Co']);
    }

    public function test_gated_student_can_submit_but_stays_gated_until_approved(): void
    {
        [$student, $batch] = $this->studentWithSheet('draft');
        $company = Company::create(['name' => 'Pick Me Corp', 'address' => 'Tagbilaran', 'is_active' => true]);
        Sanctum::actingAs($student, ['*']);

        $this->postJson('/api/student/info-sheet', [
            'status' => 'submitted',
            'personal_info' => ['last_name' => 'Cruz', 'first_name' => 'Ana', 'contact_number' => '0917'],
            'academic_info' => ['year_level' => '4th Year'],
            'ojt_info' => ['company_id' => $company->id, 'host_company' => $company->name],
        ])->assertOk()->assertJsonPath('submission_status', 'submitted');

        // Still gated (only approval lifts it) — journal endpoints stay blocked.
        $this->getJson('/api/student/journal-entries')->assertStatus(403);
    }

    public function test_gate_lifts_once_enrolled(): void
    {
        // An enrolled student (Accept created the batch_students row) is no
        // longer gated even before any approved sheet exists.
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $this->getJson('/api/student/journal-entries')->assertOk();
    }

    public function test_store_is_locked_once_approved(): void
    {
        [$student] = $this->studentWithSheet('approved');
        Sanctum::actingAs($student, ['*']);

        $this->postJson('/api/student/info-sheet', [
            'status' => 'draft',
            'personal_info' => ['last_name' => 'Cruz', 'first_name' => 'Ana'],
            'academic_info' => [],
            'ojt_info' => [],
        ])->assertStatus(422);
    }

    public function test_student_can_download_their_info_sheet_pdf(): void
    {
        [$student] = $this->studentWithSheet('submitted');
        Sanctum::actingAs($student, ['*']);

        $response = $this->get('/api/student/info-sheet/pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_rejected_sheet_can_be_edited_and_resubmitted(): void
    {
        [$student, , $sheet] = $this->studentWithSheet('rejected');
        $sheet->update(['rejection_reason' => 'Company address missing.']);
        Sanctum::actingAs($student, ['*']);

        $response = $this->postJson('/api/student/info-sheet', [
            'status' => 'submitted',
            'personal_info' => ['last_name' => 'Cruz', 'first_name' => 'Ana'],
            'academic_info' => [],
            'ojt_info' => ['company_address' => 'Now provided'],
        ]);

        $response->assertOk()->assertJsonPath('submission_status', 'submitted');
        // Resubmission supersedes the prior rejection reason.
        $this->assertNull($response->json('rejection_reason'));
    }
}
