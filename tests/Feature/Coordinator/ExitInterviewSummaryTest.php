<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentExitInterview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The aggregate Summary Report on Student Exit Interview — every in-scope
 * intern's answer to Q1 gathered together, then Q2, and so on, instead of one
 * row per student. Hard Rule #4 narrowed 2026-09-10 to bring this in scope;
 * see PROJECT.md, Exit Interview -> Summary Report.
 */
class ExitInterviewSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function programFor(string $code, string $deptCode = 'CAST'): Program
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

    private function batchFor(Program $program, User $coordinator, string $academicYear = '2026'): Batch
    {
        return Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Batch '.uniqid(),
            'start_date' => now()->subMonths(3),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => $academicYear,
            'semester' => 'Internship',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, string|null>  $responseOverrides
     */
    private function interviewFor(Batch $batch, string $name, array $responseOverrides = [], string $status = 'submitted'): StudentExitInterview
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name, 'program_id' => $batch->program_id]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company = Company::create(['name' => 'Co '.uniqid(), 'address' => 'Addr', 'is_active' => true]);

        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'completed',
        ]);

        $responses = [];
        foreach (StudentExitInterview::QUESTION_KEYS as $key) {
            $responses[$key] = 'Answer for '.$key.'.';
        }
        foreach (StudentExitInterview::CHOICE_KEYS as $key) {
            $responses[$key] = 'yes';
        }

        $responses = array_replace($responses, $responseOverrides);

        return StudentExitInterview::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'student_info' => ['department_position' => 'Loans', 'total_hours' => '486', 'date_of_interview' => '2026-08-30'],
            'responses' => $responses,
            'submission_status' => $status,
            'submitted_at' => $status === 'draft' ? null : now(),
        ]);
    }

    public function test_it_gathers_every_students_answer_under_its_own_question(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);

        $this->interviewFor($batch, 'First Student', ['q1' => 'I learned a lot.']);
        $this->interviewFor($batch, 'Second Student', ['q1' => 'It was tough but rewarding.']);

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/coordinator/exit-interviews/summary')->assertOk();

        $q1 = collect($response->json('questions'))->firstWhere('key', 'q1');
        $this->assertCount(2, $q1['answers']);

        $texts = collect($q1['answers'])->pluck('text')->all();
        $this->assertContains('I learned a lot.', $texts);
        $this->assertContains('It was tough but rewarding.', $texts);
    }

    public function test_drafts_are_excluded_from_the_summary(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);

        $this->interviewFor($batch, 'Submitted Student', ['q1' => 'Final answer.'], 'submitted');
        $this->interviewFor($batch, 'Still Drafting', ['q1' => 'Not done yet.'], 'draft');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/coordinator/exit-interviews/summary')->assertOk();

        $this->assertSame(1, $response->json('total_respondents'));

        $q1 = collect($response->json('questions'))->firstWhere('key', 'q1');
        $names = collect($q1['answers'])->pluck('student_name')->all();
        $this->assertSame(['Submitted Student'], $names);
    }

    public function test_a_reviewed_interview_still_counts(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);

        $this->interviewFor($batch, 'Reviewed Student', ['q1' => 'Answer.'], 'reviewed');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/coordinator/exit-interviews/summary')->assertOk();

        $this->assertSame(1, $response->json('total_respondents'));
    }

    public function test_a_blank_answer_is_skipped_rather_than_listed_empty(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);

        $this->interviewFor($batch, 'Skipped One Field', ['q5' => '']);

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/coordinator/exit-interviews/summary')->assertOk();

        $q5 = collect($response->json('questions'))->firstWhere('key', 'q5');
        $this->assertCount(0, $q5['answers']);

        // Every other question this student did answer still lists them.
        $q4 = collect($response->json('questions'))->firstWhere('key', 'q4');
        $this->assertCount(1, $q4['answers']);
    }

    public function test_choice_tallies_add_up_including_unanswered(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);

        $this->interviewFor($batch, 'Said Yes', ['q2_choice' => 'yes']);
        $this->interviewFor($batch, 'Said No', ['q2_choice' => 'no']);
        $this->interviewFor($batch, 'Left It Blank', ['q2_choice' => null]);

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/coordinator/exit-interviews/summary')->assertOk();

        $q2 = collect($response->json('questions'))->firstWhere('key', 'q2');
        $this->assertSame(['yes' => 1, 'no' => 1, 'unanswered' => 1], $q2['tally']);
        $this->assertSame('q2_choice', $q2['choice_key']);

        // A choice-only answer (no free text) still appears, with its choice set.
        $blankChoiceRow = collect($q2['answers'])->firstWhere('student_name', 'Left It Blank');
        $this->assertNotNull($blankChoiceRow);
        $this->assertNull($blankChoiceRow['choice']);
    }

    public function test_an_out_of_scope_program_filter_is_refused(): void
    {
        $mine = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($mine);

        $theirs = $this->programFor('BSBA-FM', 'CABM-B');

        Sanctum::actingAs($coordinator);

        $this->getJson("/api/coordinator/exit-interviews/summary?program_id={$theirs->id}")
            ->assertForbidden();
    }

    public function test_only_in_scope_programs_feed_the_summary(): void
    {
        $mine = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($mine);
        $this->interviewFor($this->batchFor($mine, $coordinator), 'In Scope Student');

        $theirs = $this->programFor('BSBA-FM', 'CABM-B');
        $this->interviewFor($this->batchFor($theirs, $this->coordinatorFor($theirs)), 'Out Of Scope Student');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/coordinator/exit-interviews/summary')->assertOk();

        $this->assertSame(1, $response->json('total_respondents'));

        $q1 = collect($response->json('questions'))->firstWhere('key', 'q1');
        $names = collect($q1['answers'])->pluck('student_name')->all();
        $this->assertSame(['In Scope Student'], $names);
    }

    public function test_academic_year_defaults_to_the_most_recent_and_can_be_narrowed(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);

        $older = $this->batchFor($program, $coordinator, '2024-2025');
        $newer = $this->batchFor($program, $coordinator, '2025-2026');

        $this->interviewFor($older, 'Old Cohort Student');
        $this->interviewFor($newer, 'New Cohort Student');

        Sanctum::actingAs($coordinator);

        // No academic_year given -> defaults to the most recent.
        $default = $this->getJson('/api/coordinator/exit-interviews/summary')->assertOk();
        $this->assertSame('2025-2026', $default->json('academic_year'));
        $this->assertSame(1, $default->json('total_respondents'));

        // Explicitly asking for the older year narrows to it.
        $filtered = $this->getJson('/api/coordinator/exit-interviews/summary?academic_year=2024-2025')->assertOk();
        $this->assertSame(1, $filtered->json('total_respondents'));

        $q1 = collect($filtered->json('questions'))->firstWhere('key', 'q1');
        $names = collect($q1['answers'])->pluck('student_name')->all();
        $this->assertSame(['Old Cohort Student'], $names);
    }

    public function test_a_coordinator_with_no_submissions_gets_an_empty_but_valid_response(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/coordinator/exit-interviews/summary')->assertOk();

        $this->assertSame(0, $response->json('total_respondents'));
        $this->assertCount(14, $response->json('questions'));

        foreach ($response->json('questions') as $question) {
            $this->assertSame([], $question['answers']);
        }
    }
}
