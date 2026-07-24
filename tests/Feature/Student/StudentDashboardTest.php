<?php

namespace Tests\Feature\Student;

use App\Models\JournalEntry;
use App\Models\WeeklyLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class StudentDashboardTest extends TestCase
{
    use RefreshDatabase;
    use EnrollsStudentInBatch;

    public function test_enrolled_student_sees_real_dashboard_stats_and_internship_details(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $enrollment = $student->batchEnrollment;

        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $enrollment->batch_id,
            'entry_date' => now()->subDays(3)->toDateString(),
            'content' => ['task_performed' => 'Did work.'],
            'status' => 'submitted',
            'submitted_at' => now()->subDays(3),
        ]);

        WeeklyLog::create([
            'batch_id' => $enrollment->batch_id,
            'student_id' => $student->id,
            'supervisor_id' => $enrollment->supervisor_id,
            'week_start' => Carbon::now()->subWeek()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'week_end' => Carbon::now()->subWeek()->startOfWeek(Carbon::MONDAY)->addDays(4)->toDateString(),
            'status' => 'approved',
            'submitted_at' => now()->subWeek(),
            'reviewed_at' => now()->subDays(6),
            'narrative' => 'Approved week.',
        ]);

        $response = $this->getJson('/api/student/dashboard');

        $response->assertOk();
        $response->assertJsonPath('stats.entries_submitted_total', 1);
        $response->assertJsonPath('stats.weekly_logs_approved', 1);
        $response->assertJsonPath('internship.host_company', $enrollment->company->name);
        $response->assertJsonPath('internship.supervisor', $enrollment->supervisor->name);
        $response->assertJsonPath('internship.coordinator', $enrollment->batch->coordinator->name);
        $this->assertIsInt($response->json('progress.ojt_duration_percent'));
        $this->assertIsInt($response->json('progress.weekly_reports_approved_percent'));
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->getJson('/api/student/dashboard');

        $response->assertStatus(401);
    }
}
