<?php

namespace Tests\Feature\Student;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentInfoSheetTest extends TestCase
{
    use RefreshDatabase;

    private function enrolledStudent(): User
    {
        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true]);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company = Company::create(['name' => 'TechPH Inc.', 'address' => 'Cebu City', 'is_active' => true]);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Program 2025-A',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        return $student;
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
}
