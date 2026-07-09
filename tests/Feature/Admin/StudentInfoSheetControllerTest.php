<?php

namespace Tests\Feature\Admin;

use App\Models\Batch;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentInfoSheetControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function studentWithBatch(Department $department): array
    {
        $program = Program::create([
            'department_id' => $department->id,
            'code' => 'PRG-'.uniqid(),
            'name' => 'Test Program',
            'is_active' => true,
        ]);

        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Batch '.uniqid(),
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        return [$student, $batch];
    }

    private function minimalSheetPayload(): array
    {
        return [
            'personal_info' => ['last_name' => 'Cruz', 'first_name' => 'Juan'],
            'academic_info' => ['program_course' => 'BS Information Technology'],
            'ojt_info' => ['host_company' => 'TechPH Inc.'],
        ];
    }

    public function test_index_lists_students_with_submission_status(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        [$student, $batch] = $this->studentWithBatch($department);

        StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            ...$this->minimalSheetPayload(),
            'submission_status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->getJson('/api/admin/info-sheets');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $student->id);
        $this->assertNotNull($row);
        $this->assertSame('submitted', $row['submission_status']);
    }

    public function test_department_filter_only_returns_students_in_that_department(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $cast = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        $cabm = Department::create(['code' => 'CABM-B', 'name' => 'College of Business Management', 'is_active' => true]);

        [$castStudent] = $this->studentWithBatch($cast);
        [$cabmStudent] = $this->studentWithBatch($cabm);

        $response = $this->getJson('/api/admin/info-sheets?department_id='.$cast->id);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($castStudent->id));
        $this->assertFalse($ids->contains($cabmStudent->id));
    }

    public function test_status_filter_not_started_only_returns_students_without_a_sheet(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        [$submittedStudent, $batch] = $this->studentWithBatch($department);
        [$freshStudent] = $this->studentWithBatch($department);

        StudentInformationSheet::create([
            'student_id' => $submittedStudent->id,
            'batch_id' => $batch->id,
            ...$this->minimalSheetPayload(),
            'submission_status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->getJson('/api/admin/info-sheets?status=not-started');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($freshStudent->id));
        $this->assertFalse($ids->contains($submittedStudent->id));
    }

    public function test_show_returns_existing_sheet_with_student_summary(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        [$student, $batch] = $this->studentWithBatch($department);

        StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'personal_info' => ['last_name' => 'Cruz', 'first_name' => 'Juan'],
            'academic_info' => ['program_course' => 'BS Information Technology'],
            'ojt_info' => ['host_company' => 'TechPH Inc.'],
            'submission_status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->getJson("/api/admin/info-sheets/{$student->id}");

        $response->assertOk();
        $this->assertSame('submitted', $response->json('submission_status'));
        $this->assertSame('TechPH Inc.', $response->json('ojt_info.host_company'));
        $this->assertSame($student->id, $response->json('student.id'));
    }

    public function test_show_returns_fallback_shape_for_student_without_a_sheet(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        [$student] = $this->studentWithBatch($department);

        $response = $this->getJson("/api/admin/info-sheets/{$student->id}");

        $response->assertOk();
        $this->assertNull($response->json('submission_status'));
        $this->assertNull($response->json('id'));
        $this->assertSame($student->name, trim($response->json('personal_info.first_name').' '.$response->json('personal_info.last_name')));
    }

    public function test_show_404s_for_a_non_student_user(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $coordinator = User::factory()->create(['role' => 'coordinator']);

        $this->getJson("/api/admin/info-sheets/{$coordinator->id}")->assertStatus(404);
    }

    public function test_non_admin_cannot_access_info_sheets(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']), ['*']);

        $this->getJson('/api/admin/info-sheets')->assertStatus(403);
    }
}
