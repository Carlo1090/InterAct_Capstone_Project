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

    private function enroll(Batch $batch, string $name, ?Company $company = null): User
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name, 'program_id' => $batch->program_id]);

        app(EnrollmentService::class)->enrollOrReactivate(
            $batch->id,
            $student->id,
            ($company ?? $this->company($batch->isSupervisorSupported()))->id,
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
        // Pending is the default view — "what is waiting on me" is the reason
        // to open this page, so an unfiltered request must never land on
        // Approved or Returned.
        $this->assertSame('pending', $response->json('status'));
    }

    /**
     * A department can run several coordinator-centered cohorts at several
     * host companies at once, and a coordinator collecting one batch's journals
     * should not have to read past the others.
     */
    public function test_the_queue_can_be_filtered_by_batch_and_by_company(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);

        $alpha = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $beta = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);

        $north = $this->company(withSupervisor: false);
        $south = $this->company(withSupervisor: false);

        $alphaIntern = $this->enroll($alpha, 'Alpha North', $north);
        $betaIntern = $this->enroll($beta, 'Beta South', $south);

        $this->log($alphaIntern, $alpha, 1, 'pending');
        $this->log($betaIntern, $beta, 1, 'pending');

        Sanctum::actingAs($coordinator);

        $this->getJson('/api/coordinator/journal-review')->assertOk()->assertJsonCount(2, 'logs');

        $byBatch = $this->getJson('/api/coordinator/journal-review?batch_id='.$alpha->id)->assertOk();
        $this->assertCount(1, $byBatch->json('logs'));
        $this->assertSame('Alpha North', $byBatch->json('logs.0.student_name'));

        $byCompany = $this->getJson('/api/coordinator/journal-review?company_id='.$south->id)->assertOk();
        $this->assertCount(1, $byCompany->json('logs'));
        $this->assertSame('Beta South', $byCompany->json('logs.0.student_name'));
        // The company rides on the enrollment, not on the log — it is resolved
        // per (student, batch) pair so the filter's effect is visible in its own
        // results rather than being invisible.
        $this->assertSame($south->name, $byCompany->json('logs.0.company'));

        // Both filters together, naming a pair that does not exist.
        $this->getJson("/api/coordinator/journal-review?batch_id={$alpha->id}&company_id={$south->id}")
            ->assertOk()
            ->assertJsonCount(0, 'logs');
    }

    /**
     * The pill counts come from the same filtered query the table does, or
     * "Approved 2" opens onto an empty table whenever a batch filter is set.
     */
    public function test_the_status_pill_counts_respect_the_filters(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);

        $alpha = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $beta = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);

        $alphaIntern = $this->enroll($alpha, 'Alpha Intern');
        $betaIntern = $this->enroll($beta, 'Beta Intern');

        $this->log($alphaIntern, $alpha, 1, 'pending');
        $this->log($alphaIntern, $alpha, 2, 'approved');
        $this->log($betaIntern, $beta, 1, 'pending');

        Sanctum::actingAs($coordinator);

        $all = $this->getJson('/api/coordinator/journal-review')->assertOk();
        $this->assertSame(2, $all->json('counts.pending'));
        $this->assertSame(1, $all->json('counts.approved'));

        $scoped = $this->getJson('/api/coordinator/journal-review?batch_id='.$alpha->id)->assertOk();
        $this->assertSame(1, $scoped->json('counts.pending'));
        $this->assertSame(1, $scoped->json('counts.approved'));
        $this->assertSame(0, $scoped->json('counts.returned'));
    }

    /**
     * The dropdowns are built from the UNFILTERED set. A filter list that
     * narrows to its own selection cannot be changed without clearing it first.
     */
    public function test_the_filter_options_do_not_shrink_to_the_current_selection(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);

        $alpha = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $beta = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $supervised = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_SUPERVISOR);

        $this->enroll($alpha, 'Alpha Intern', $this->company(withSupervisor: false));
        $this->enroll($beta, 'Beta Intern', $this->company(withSupervisor: false));
        // A supervisor-supported cohort is somebody else's to review, so its
        // batch and company must not be offered here at all.
        $this->enroll($supervised, 'Supervised Intern');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/coordinator/journal-review?batch_id='.$alpha->id)->assertOk();

        $this->assertCount(2, $response->json('filters.batches'));
        $this->assertCount(2, $response->json('filters.companies'));
        $this->assertEqualsCanonicalizing(
            [$alpha->id, $beta->id],
            array_column($response->json('filters.batches'), 'id'),
        );
    }

    /**
     * One filter bar serves both tabs, so the intern index has to honour the
     * same narrowing — otherwise switching tabs silently changes what is being
     * looked at.
     */
    public function test_the_interns_index_honours_the_batch_and_company_filters(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);

        $alpha = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $beta = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);

        $north = $this->company(withSupervisor: false);

        $this->enroll($alpha, 'Alpha North', $north);
        $this->enroll($beta, 'Beta Elsewhere');

        Sanctum::actingAs($coordinator);

        $this->getJson('/api/coordinator/journal-review/interns')->assertOk()->assertJsonCount(2, 'interns');

        $byBatch = $this->getJson('/api/coordinator/journal-review/interns?batch_id='.$beta->id)->assertOk();
        $this->assertCount(1, $byBatch->json('interns'));
        $this->assertSame('Beta Elsewhere', $byBatch->json('interns.0.student_name'));

        $byCompany = $this->getJson('/api/coordinator/journal-review/interns?company_id='.$north->id)->assertOk();
        $this->assertCount(1, $byCompany->json('interns'));
        $this->assertSame('Alpha North', $byCompany->json('interns.0.student_name'));
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

    /**
     * The Journal Review and Daily Time Record nav items are each hidden from a
     * coordinator with no cohort of the matching kind, which the SPA can only do
     * if the payload says so before any page loads. Same mechanism as the
     * student's `student_dtr_enabled`.
     *
     * THE TWO FLAGS ARE OPPOSITES, and this test exists mostly to keep them
     * that way: journals are reviewed by the coordinator on COORDINATOR-CENTERED
     * cohorts, while the Daily Time Record runs only on SUPERVISOR-SUPPORTED
     * ones (DtrService::runsForEnrollment() — the whole scheme rests on a
     * company supervisor being on site to anchor a geofence and correct
     * punches). Wiring both to one flag would hide the DTR from exactly the
     * coordinators whose interns clock in.
     */
    public function test_the_auth_payload_reports_which_kinds_of_cohort_the_coordinator_runs(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);

        Sanctum::actingAs($coordinator);

        // No batches at all: neither surface has anything to show.
        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('coordinator_has_centered_batch', false)
            ->assertJsonPath('coordinator_has_supervised_batch', false);

        $this->batchFor($program, $coordinator, Batch::OJT_TYPE_SUPERVISOR);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('coordinator_has_centered_batch', false)
            ->assertJsonPath('coordinator_has_supervised_batch', true);

        $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('coordinator_has_centered_batch', true)
            ->assertJsonPath('coordinator_has_supervised_batch', true);
    }

    /**
     * A coordinator's own program_id is always null (see coordinatorProgramIds()
     * / the "Coordinator scope" note in PROJECT.md), so program.department is
     * never the source of their department on the auth payload — it comes
     * through the coordinator_departments pivot instead. Before this,
     * CoordinatorLayout.vue's header silently fell back to a hardcoded
     * placeholder ("Business Administration") for every coordinator, because
     * the field it was reading was always null.
     */
    public function test_the_auth_payload_reports_the_coordinators_own_department(): void
    {
        $program = $this->programFor('BSED', 'COED');
        $coordinator = $this->coordinatorFor($program);

        Sanctum::actingAs($coordinator);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('departments_coordinated.0.code', 'COED');
    }

    /**
     * A coordinator running ONLY coordinator-centered cohorts has no intern who
     * can clock in, so the Daily Time Record item goes — this is the project
     * owner's own client, who confirmed the DTR does not apply to them. Pinned
     * separately because it is the case the two flags would agree on if
     * somebody ever "simplified" them into one.
     */
    public function test_a_coordinator_with_only_centered_cohorts_loses_the_daily_time_record(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);

        Sanctum::actingAs($coordinator);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('coordinator_has_centered_batch', true)
            ->assertJsonPath('coordinator_has_supervised_batch', false);
    }
}
