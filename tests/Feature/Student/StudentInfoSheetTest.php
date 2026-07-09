<?php

namespace Tests\Feature\Student;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class StudentInfoSheetTest extends TestCase
{
    use RefreshDatabase;
    use EnrollsStudentInBatch;

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
}
