<?php

namespace Tests\Feature\Student;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class WeeklyActivityLogTest extends TestCase
{
    use RefreshDatabase;
    use EnrollsStudentInBatch;

    public function test_student_can_build_a_weekly_activity_log_and_download_its_pdf(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = now()->startOfWeek()->toDateString();
        $weekEnd = now()->startOfWeek()->addDays(6)->toDateString();

        $logResponse = $this->postJson('/api/student/weekly-activity-logs', [
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'area_assigned' => 'Software Development Team',
            'no_of_hours' => 40,
        ]);
        $logResponse->assertCreated();
        $logId = $logResponse->json('id');

        $entryResponse = $this->postJson("/api/student/weekly-activity-logs/{$logId}/entries", [
            'inclusive_date_start' => $weekStart,
            'inclusive_date_end' => $weekEnd,
            'activities' => 'Built the weekly activity log feature.',
            'documents_records' => 'Pull request #42',
            'objectives' => 'Ship the SIPP weekly activity report.',
            'supervisor_name' => 'Engr. Ramon Villanueva',
            'supervisor_position' => 'Senior Software Engineer',
        ]);
        $entryResponse->assertCreated();

        $pdfResponse = $this->get("/api/student/weekly-activity-logs/{$logId}/pdf");

        $pdfResponse->assertOk();
        $this->assertStringContainsString('application/pdf', $pdfResponse->headers->get('Content-Type'));

        $pdfContent = $pdfResponse->getContent();
        $this->assertNotEmpty($pdfContent);

        Storage::disk('local')->put('tmp/weekly-activity-log-test.pdf', $pdfContent);
        $bytes = Storage::disk('local')->size('tmp/weekly-activity-log-test.pdf');
        fwrite(STDERR, "\nWeekly activity log PDF size: {$bytes} bytes\n");
        $this->assertGreaterThan(0, $bytes);
    }

    public function test_student_cannot_access_another_students_weekly_activity_log(): void
    {
        $studentA = $this->enrolledStudent();
        $studentB = $this->enrolledStudent();

        Sanctum::actingAs($studentA, ['*']);
        $logResponse = $this->postJson('/api/student/weekly-activity-logs', [
            'week_start' => now()->startOfWeek()->toDateString(),
            'week_end' => now()->startOfWeek()->addDays(6)->toDateString(),
        ]);
        $logId = $logResponse->json('id');

        Sanctum::actingAs($studentB, ['*']);
        $this->getJson("/api/student/weekly-activity-logs/{$logId}")->assertStatus(403);
        $this->getJson("/api/student/weekly-activity-logs/{$logId}/pdf")->assertStatus(403);
    }
}
