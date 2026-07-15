<?php

namespace Tests\Feature\Student;

use App\Models\BatchStudent;
use App\Models\StudentInformationSheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class StudentInfoSheetTest extends TestCase
{
    use RefreshDatabase;
    use EnrollsStudentInBatch;

    /**
     * An already-approved (enrolled) sheet, seeded with known values for the
     * fields that lock post-approval (year_level, company_id/host_company)
     * so tests can assert they survive an edit attempt untouched.
     */
    private function approvedInfoSheetFor(User $student): StudentInformationSheet
    {
        $enrollment = BatchStudent::where('student_id', $student->id)->firstOrFail();

        return StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $enrollment->batch_id,
            'personal_info' => [
                'last_name' => 'Dela Cruz',
                'first_name' => 'Juan',
                'contact_number' => '0917-000-0000',
            ],
            'academic_info' => [
                'program_course' => $enrollment->batch->program->name,
                'year_level' => '3rd Year',
                'department' => $enrollment->batch->program->department->name,
                'internship_coordinator' => $enrollment->batch->coordinator->name,
            ],
            'ojt_info' => [
                'company_id' => $enrollment->company_id,
                'host_company' => $enrollment->company->name,
            ],
            'emergency_contact' => [],
            'submission_status' => 'approved',
            'submitted_at' => now(),
        ]);
    }

    public function test_student_can_store_an_info_sheet(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $payload = [
            'status' => 'submitted',
            'personal_info' => [
                'last_name' => 'Dela Cruz',
                'first_name' => 'Juan',
            ],
            'academic_info' => [
                'program_course' => 'BS Information Technology',
            ],
            'ojt_info' => [
                'host_company' => 'TechPH Inc.',
                'ojt_start_date' => now()->subMonth()->toDateString(),
                'ojt_end_date' => now()->addMonth()->toDateString(),
            ],
        ];

        $response = $this->postJson('/api/student/info-sheet', $payload);

        $response->assertOk()->assertJsonPath('submission_status', 'submitted');

        $this->assertDatabaseHas('student_information_sheets', [
            'student_id' => $student->id,
            'submission_status' => 'submitted',
        ]);
    }

    public function test_enrolled_student_can_fetch_scaffolded_info_sheet(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $response = $this->getJson('/api/student/info-sheet');

        $response->assertOk();
        $this->assertNull($response->json('id'));
        $this->assertNull($response->json('submission_status'));
        $this->assertStringStartsWith('TechPH Inc.', $response->json('ojt_info.host_company'));
        $this->assertSame('BS Information Technology', $response->json('academic_info.program_course'));
    }

    public function test_unenrolled_student_cannot_store_an_info_sheet(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student, ['*']);

        $response = $this->postJson('/api/student/info-sheet', [
            'status' => 'draft',
            'personal_info' => ['last_name' => 'Doe', 'first_name' => 'Jane'],
            'academic_info' => [],
            'ojt_info' => [],
        ]);

        $response->assertStatus(422);
    }

    public function test_year_level_must_be_one_of_the_defined_options(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $basePayload = [
            'status' => 'draft',
            'personal_info' => ['last_name' => 'Dela Cruz', 'first_name' => 'Juan'],
            'ojt_info' => [],
        ];

        $invalid = $this->postJson('/api/student/info-sheet', [
            ...$basePayload,
            'academic_info' => ['year_level' => 'Senior'],
        ]);
        $invalid->assertStatus(422);
        $invalid->assertJsonValidationErrors(['academic_info.year_level']);

        $valid = $this->postJson('/api/student/info-sheet', [
            ...$basePayload,
            'academic_info' => ['year_level' => '3rd Year'],
        ]);
        $valid->assertOk();
    }

    public function test_an_approved_sheet_can_still_update_non_locked_fields(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);
        $sheet = $this->approvedInfoSheetFor($student);

        $originalCompanyId = $sheet->ojt_info['company_id'];
        $originalHostCompany = $sheet->ojt_info['host_company'];
        $originalProgram = $sheet->academic_info['program_course'];
        $originalDepartment = $sheet->academic_info['department'];
        $originalCoordinator = $sheet->academic_info['internship_coordinator'];

        $response = $this->postJson('/api/student/info-sheet', [
            'status' => 'submitted',
            'personal_info' => [
                'last_name' => 'Dela Cruz',
                'first_name' => 'Juan',
                'contact_number' => '0918-111-1111',
            ],
            'academic_info' => [
                'year_level' => '1st Year',
            ],
            'ojt_info' => [
                'company_id' => null,
                'host_company' => 'A Different Company',
            ],
        ]);

        $response->assertOk();

        $sheet->refresh();
        $this->assertSame('0918-111-1111', $sheet->personal_info['contact_number']);
        $this->assertSame('3rd Year', $sheet->academic_info['year_level']);
        $this->assertSame($originalProgram, $sheet->academic_info['program_course']);
        $this->assertSame($originalDepartment, $sheet->academic_info['department']);
        $this->assertSame($originalCoordinator, $sheet->academic_info['internship_coordinator']);
        $this->assertSame($originalCompanyId, $sheet->ojt_info['company_id']);
        $this->assertSame($originalHostCompany, $sheet->ojt_info['host_company']);
        $this->assertSame('approved', $sheet->submission_status);
    }

    public function test_an_approved_sheet_edit_does_not_re_gate_the_student(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);
        $this->approvedInfoSheetFor($student);

        $this->postJson('/api/student/info-sheet', [
            'status' => 'submitted',
            'personal_info' => [
                'last_name' => 'Dela Cruz',
                'first_name' => 'Juan',
                'contact_number' => '0918-222-2222',
            ],
            'academic_info' => [],
            'ojt_info' => [],
        ])->assertOk();

        $this->assertFalse($student->fresh()->isInfoSheetGated());
    }
}
