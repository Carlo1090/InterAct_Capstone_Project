<?php

namespace Tests\Feature\Supervisor;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\User;
use App\Models\WeeklyLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupervisorJournalReviewTest extends TestCase
{
    use RefreshDatabase;

    private function program(): Program
    {
        $department = Department::firstOrCreate(['code' => 'CAST'], ['name' => 'CAST Dept', 'is_active' => true]);

        return Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => 'BSIT'],
            ['name' => 'BSIT Program', 'is_active' => true]
        );
    }

    private function batch(Program $program): Batch
    {
        return Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => User::factory()->create(['role' => 'coordinator'])->id,
            'name' => 'Batch '.uniqid(),
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026',
            'semester' => 'Internship',
            'is_active' => true,
        ]);
    }

    private function intern(User $supervisor, Batch $batch): User
    {
        $student = User::factory()->create(['role' => 'student', 'program_id' => $batch->program_id]);
        $company = Company::create(['name' => 'Co '.uniqid(), 'address' => 'A', 'is_active' => true]);
        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        return $student;
    }

    private function log(User $student, Batch $batch, string $status = 'pending', bool $submitted = true): WeeklyLog
    {
        return WeeklyLog::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'week_start' => now()->startOfWeek()->toDateString(),
            'week_end' => now()->startOfWeek()->addDays(6)->toDateString(),
            'status' => $status,
            'narrative' => 'Weekly narrative for review.',
            'submitted_at' => $submitted ? now() : null,
        ]);
    }

    /** @return array{0: User, 1: Batch, 2: User} supervisor, batch, student */
    private function scenario(): array
    {
        $program = $this->program();
        $batch = $this->batch($program);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $student = $this->intern($supervisor, $batch);

        return [$supervisor, $batch, $student];
    }

    public function test_index_defaults_to_pending_and_excludes_drafts(): void
    {
        [$supervisor, $batch, $student] = $this->scenario();
        $this->log($student, $batch, 'pending', submitted: true);
        // A draft (not submitted) must not appear.
        WeeklyLog::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'week_start' => now()->startOfWeek()->subWeek()->toDateString(),
            'week_end' => now()->startOfWeek()->subWeek()->addDays(6)->toDateString(),
            'status' => 'pending',
            'narrative' => 'draft',
            'submitted_at' => null,
        ]);

        Sanctum::actingAs($supervisor, ['*']);

        $response = $this->getJson('/api/supervisor/journals');
        $response->assertOk();
        $response->assertJsonPath('status', 'pending');
        $this->assertCount(1, $response->json('logs'));
    }

    public function test_show_returns_narrative_and_daily_entries_in_scope(): void
    {
        [$supervisor, $batch, $student] = $this->scenario();
        $weeklyLog = $this->log($student, $batch, 'pending');
        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'entry_date' => now()->startOfWeek()->addDay()->toDateString(),
            'content' => ['task_performed' => 'Did work.'],
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($supervisor, ['*']);

        $response = $this->getJson("/api/supervisor/journals/{$weeklyLog->id}");
        $response->assertOk();
        $response->assertJsonPath('narrative', 'Weekly narrative for review.');
        $response->assertJsonPath('reviewable', true);
        $this->assertCount(1, $response->json('daily_entries'));
    }

    public function test_approve_sets_status_reviewer_and_timestamp(): void
    {
        [$supervisor, $batch, $student] = $this->scenario();
        $weeklyLog = $this->log($student, $batch, 'pending');

        Sanctum::actingAs($supervisor, ['*']);

        $this->postJson("/api/supervisor/journals/{$weeklyLog->id}/approve")->assertOk();

        $weeklyLog->refresh();
        $this->assertSame('approved', $weeklyLog->status);
        $this->assertSame($supervisor->id, $weeklyLog->supervisor_id);
        $this->assertNotNull($weeklyLog->reviewed_at);
    }

    public function test_return_requires_comment_and_sets_returned(): void
    {
        [$supervisor, $batch, $student] = $this->scenario();
        $weeklyLog = $this->log($student, $batch, 'pending');

        Sanctum::actingAs($supervisor, ['*']);

        // Empty comment rejected.
        $this->postJson("/api/supervisor/journals/{$weeklyLog->id}/return", ['supervisor_comment' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supervisor_comment');

        // With a comment, it returns the log.
        $this->postJson("/api/supervisor/journals/{$weeklyLog->id}/return", ['supervisor_comment' => 'Please add more detail on week 2 tasks.'])
            ->assertOk();

        $weeklyLog->refresh();
        $this->assertSame('returned', $weeklyLog->status);
        $this->assertSame('Please add more detail on week 2 tasks.', $weeklyLog->supervisor_comment);
        $this->assertSame($supervisor->id, $weeklyLog->supervisor_id);
        $this->assertNotNull($weeklyLog->reviewed_at);
    }

    public function test_reviewing_another_supervisors_log_is_forbidden(): void
    {
        [, $batch, $student] = $this->scenario();
        $weeklyLog = $this->log($student, $batch, 'pending');

        $otherSupervisor = User::factory()->create(['role' => 'supervisor']);
        Sanctum::actingAs($otherSupervisor, ['*']);

        $this->getJson("/api/supervisor/journals/{$weeklyLog->id}")->assertStatus(403);
        $this->postJson("/api/supervisor/journals/{$weeklyLog->id}/approve")->assertStatus(403);
        $this->postJson("/api/supervisor/journals/{$weeklyLog->id}/return", ['supervisor_comment' => 'x'])->assertStatus(403);
    }

    public function test_reviewing_a_draft_is_rejected(): void
    {
        [$supervisor, $batch, $student] = $this->scenario();
        $draft = $this->log($student, $batch, 'pending', submitted: false);

        Sanctum::actingAs($supervisor, ['*']);

        $this->postJson("/api/supervisor/journals/{$draft->id}/approve")->assertStatus(422);
        $this->postJson("/api/supervisor/journals/{$draft->id}/return", ['supervisor_comment' => 'Fix it'])->assertStatus(422);

        $draft->refresh();
        $this->assertSame('pending', $draft->status);
        $this->assertNull($draft->reviewed_at);
    }

    public function test_cannot_re_review_an_approved_log(): void
    {
        [$supervisor, $batch, $student] = $this->scenario();
        $weeklyLog = $this->log($student, $batch, 'approved');

        Sanctum::actingAs($supervisor, ['*']);

        $this->postJson("/api/supervisor/journals/{$weeklyLog->id}/approve")->assertStatus(422);
    }
}
