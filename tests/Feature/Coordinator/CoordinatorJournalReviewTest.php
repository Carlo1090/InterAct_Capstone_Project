<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\User;
use App\Models\WeeklyLog;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The coordinator's OWN review surface — the queue, the per-intern notebook and
 * the two verdicts — for batches running under the `coordinator` OJT type.
 *
 * The boundary is the point of most of these: a coordinator must NOT gain
 * approve/return over a supervisor-supported batch, where the verdict belongs
 * to the company that actually supervised the work. Their existing read-only
 * Weekly Journals page still covers every batch in scope and is untouched.
 */
class CoordinatorJournalReviewTest extends TestCase
{
    use RefreshDatabase;

    private function programFor(string $code = 'BSIT', string $deptCode = 'CAST'): Program
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

    private function batchFor(Program $program, User $coordinator, string $ojtType): Batch
    {
        return Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Batch '.uniqid(),
            'ojt_type' => $ojtType,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026',
            'semester' => 'Internship',
            'is_active' => true,
        ]);
    }

    private function company(bool $withSupervisor): Company
    {
        $company = Company::create(['name' => 'Co '.uniqid(), 'address' => 'Addr', 'is_active' => true]);

        if ($withSupervisor) {
            CompanySupervisor::create([
                'company_id' => $company->id,
                'user_id' => User::factory()->create(['role' => 'supervisor'])->id,
                'position' => 'Supervisor',
            ]);
        }

        return $company;
    }

    private function enroll(Batch $batch, string $name): User
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name, 'program_id' => $batch->program_id]);

        app(EnrollmentService::class)->enrollOrReactivate(
            $batch->id,
            $student->id,
            $this->company($batch->isSupervisorSupported())->id,
        );

        return $student;
    }

    private function log(User $student, Batch $batch, int $weeksAgo, string $status, bool $submitted = true): WeeklyLog
    {
        $weekStart = now()->startOfWeek()->subWeeks($weeksAgo);

        return WeeklyLog::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->copy()->addDays(6)->toDateString(),
            'status' => $status,
            'narrative' => "MONDAY\nDid the work.",
            'submitted_at' => $submitted ? $weekStart->copy()->addDays(7) : null,
        ]);
    }

    public function test_the_queue_lists_only_coordinator_centered_submitted_logs(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);

        $centered = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $supervised = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_SUPERVISOR);

        $mine = $this->enroll($centered, 'Centered Intern');
        $theirs = $this->enroll($supervised, 'Supervised Intern');

        $this->log($mine, $centered, 2, 'pending');
        $this->log($mine, $centered, 3, 'pending', submitted: false); // a draft
        $this->log($theirs, $supervised, 2, 'pending');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/coordinator/journal-review')->assertOk();

        $this->assertCount(1, $response->json('logs'));
        $this->assertSame('Centered Intern', $response->json('logs.0.student_name'));
        $this->assertSame(1, $response->json('counts.pending'));
    }

    public function test_a_supervisor_supported_log_cannot_be_opened_or_approved_by_the_coordinator(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $supervised = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_SUPERVISOR);
        $student = $this->enroll($supervised, 'Supervised Intern');
        $log = $this->log($student, $supervised, 1, 'pending');

        Sanctum::actingAs($coordinator);

        $this->getJson("/api/coordinator/journal-review/{$log->id}")->assertForbidden();
        $this->postJson("/api/coordinator/journal-review/{$log->id}/approve")->assertForbidden();

        $this->assertSame('pending', $log->fresh()->status);
    }

    public function test_the_coordinator_approves_a_journal_and_is_recorded_as_the_reviewer(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $student = $this->enroll($batch, 'Centered Intern');
        $log = $this->log($student, $batch, 1, 'pending');

        Sanctum::actingAs($coordinator);

        $this->postJson("/api/coordinator/journal-review/{$log->id}/approve")->assertOk();

        $log->refresh();
        $this->assertSame('approved', $log->status);
        $this->assertSame($coordinator->id, $log->supervisor_id);
        $this->assertNotNull($log->reviewed_at);
    }

    public function test_returning_a_journal_requires_a_comment(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $student = $this->enroll($batch, 'Centered Intern');
        $log = $this->log($student, $batch, 1, 'pending');

        Sanctum::actingAs($coordinator);

        $this->postJson("/api/coordinator/journal-review/{$log->id}/return", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supervisor_comment');

        $this->postJson("/api/coordinator/journal-review/{$log->id}/return", [
            'supervisor_comment' => 'Please expand Wednesday.',
        ])->assertOk();

        $log->refresh();
        $this->assertSame('returned', $log->status);
        $this->assertSame('Please expand Wednesday.', $log->supervisor_comment);
    }

    public function test_an_already_approved_journal_cannot_be_reviewed_again(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $student = $this->enroll($batch, 'Centered Intern');
        $log = $this->log($student, $batch, 1, 'approved');

        Sanctum::actingAs($coordinator);

        $this->postJson("/api/coordinator/journal-review/{$log->id}/approve")
            ->assertStatus(422);
    }

    public function test_a_coordinator_from_another_department_is_refused(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $student = $this->enroll($batch, 'Centered Intern');
        $log = $this->log($student, $batch, 1, 'pending');

        $outsider = $this->coordinatorFor($this->programFor('BSBA-FM', 'CABM-B'));
        Sanctum::actingAs($outsider);

        $this->getJson("/api/coordinator/journal-review/{$log->id}")->assertForbidden();
        $this->getJson('/api/coordinator/journal-review')->assertOk()->assertJsonCount(0, 'logs');
    }

    /**
     * The notebook — the goal of this feature's second half. The coordinator
     * reviews these journals, so they get the same whole-placement view the
     * supervisor has always had, not just the queue's one-status slice.
     */
    public function test_the_notebook_lists_every_submitted_week_oldest_first(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $student = $this->enroll($batch, 'Centered Intern');

        $this->log($student, $batch, 4, 'approved');
        $this->log($student, $batch, 3, 'returned');
        $this->log($student, $batch, 2, 'pending', submitted: false); // draft
        $this->log($student, $batch, 1, 'pending');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson("/api/coordinator/journal-review/interns/{$student->id}")->assertOk();

        $weeks = $response->json('weeks');
        $this->assertCount(3, $weeks);
        $this->assertSame('approved', $weeks[0]['status']);
        $this->assertSame('returned', $weeks[1]['status']);
        $this->assertSame('pending', $weeks[2]['status']);

        // Week numbers count over ALL logs, drafts included, so they match the
        // PDF — the gap at week 3 is honest, and is announced.
        $this->assertSame([1, 2, 4], array_column($weeks, 'week_number'));
        $this->assertSame(1, $response->json('totals.drafts_hidden'));
        $this->assertSame(1, $response->json('totals.pending'));
    }

    public function test_the_notebook_refuses_a_student_who_is_not_on_a_coordinator_centered_batch(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $supervised = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_SUPERVISOR);
        $student = $this->enroll($supervised, 'Supervised Intern');

        Sanctum::actingAs($coordinator);

        $this->getJson("/api/coordinator/journal-review/interns/{$student->id}")->assertForbidden();
    }

    public function test_the_notebook_404s_on_an_account_that_is_not_a_student(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);

        $notAStudent = User::factory()->create(['role' => 'supervisor']);

        Sanctum::actingAs($coordinator);

        $this->getJson("/api/coordinator/journal-review/interns/{$notAStudent->id}")->assertNotFound();
    }

    /**
     * Without this index an intern with nothing currently pending would be
     * unreachable — the queue only ever lists one status at a time.
     */
    public function test_the_interns_index_lists_coordinator_centered_interns_with_their_tallies(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $centered = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $supervised = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_SUPERVISOR);

        $mine = $this->enroll($centered, 'Centered Intern');
        $this->enroll($supervised, 'Supervised Intern');

        $this->log($mine, $centered, 3, 'approved');
        $this->log($mine, $centered, 2, 'approved');
        $this->log($mine, $centered, 1, 'pending');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/coordinator/journal-review/interns')->assertOk();

        $this->assertCount(1, $response->json('interns'));
        $this->assertSame('Centered Intern', $response->json('interns.0.student_name'));
        $this->assertSame(2, $response->json('interns.0.approved'));
        $this->assertSame(1, $response->json('interns.0.pending'));
    }

    public function test_the_literal_interns_segment_is_not_swallowed_by_the_weekly_log_wildcard(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);

        Sanctum::actingAs($coordinator);

        // Would 404/500 through the {weeklyLog} binding if the ordering broke.
        $this->getJson('/api/coordinator/journal-review/interns')
            ->assertOk()
            ->assertJsonStructure(['interns']);
    }

    public function test_a_supervisor_cannot_reach_the_coordinators_review_routes(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $student = $this->enroll($batch, 'Centered Intern');
        $log = $this->log($student, $batch, 1, 'pending');

        Sanctum::actingAs(User::factory()->create(['role' => 'supervisor']));

        $this->getJson('/api/coordinator/journal-review')->assertForbidden();
        $this->postJson("/api/coordinator/journal-review/{$log->id}/approve")->assertForbidden();
    }

    public function test_the_weekly_log_pdf_downloads_for_a_reviewable_journal(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $student = $this->enroll($batch, 'Centered Intern');
        $log = $this->log($student, $batch, 1, 'pending');

        JournalEntry::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'entry_date' => $log->week_start,
            'status' => 'submitted',
            'content' => ['daily_accomplishment' => 'Did the work.'],
        ]);

        Sanctum::actingAs($coordinator);

        $this->get("/api/coordinator/journal-review/{$log->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
