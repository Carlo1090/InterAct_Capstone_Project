<?php

namespace Tests\Feature\Student;

use App\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class WeeklyLogTest extends TestCase
{
    use RefreshDatabase;
    use EnrollsStudentInBatch;

    public function test_student_can_save_a_weekly_narrative_with_sipp_fields(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);

        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $student->batchEnrollment->batch_id,
            'entry_date' => $weekStart->toDateString(),
            'content' => ['Tasks Performed' => 'Kickoff meeting and environment setup.'],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->postJson('/api/student/weekly-logs', [
            'week_start' => $weekStart->toDateString(),
            'narrative' => 'This week I focused on onboarding and initial setup.',
            'issues_concerns' => 'Some VPN access delays.',
            'solutions' => 'Coordinated with IT support to resolve access.',
            'recommendations' => 'Provide VPN access before day one next batch.',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('weekly_logs', [
            'student_id' => $student->id,
            'narrative' => 'This week I focused on onboarding and initial setup.',
            'issues_concerns' => 'Some VPN access delays.',
        ]);

        $reference = $this->getJson('/api/student/weekly-logs/'.$weekStart->toDateString());

        $reference->assertOk();
        $this->assertSame('This week I focused on onboarding and initial setup.', $reference->json('narrative'));
        $this->assertCount(1, $reference->json('daily_entries'));
        $this->assertSame($weekStart->toDateString(), Carbon::parse($reference->json('daily_entries.0.entry_date'))->toDateString());
    }
}
