<?php

namespace Tests\Feature\Coordinator;

use App\Http\Controllers\Concerns\BuildsExitInterviewPdf;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentExitInterview;
use App\Models\User;
use App\Support\ExitInterview\ExitInterviewForms;
use App\Support\ExitInterviewFormLayout as Layout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The coordinator's window onto their students' exit interviews: scoped by the
 * batch's program, readable, downloadable, and editable only in the block the
 * paper form reserves for them. There is deliberately no accept/reject.
 */
class CoordinatorExitInterviewTest extends TestCase
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

    private function batchFor(Program $program, User $coordinator): Batch
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
            'academic_year' => '2026',
            'semester' => 'Internship',
            'is_active' => true,
        ]);
    }

    private function interviewFor(Batch $batch, string $name, string $status = 'submitted'): StudentExitInterview
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
        foreach (ExitInterviewForms::get('cabm')->questionKeys() as $key) {
            $responses[$key] = 'Answer for '.$key.'.';
        }
        foreach (ExitInterviewForms::get('cabm')->choiceKeys() as $key) {
            $responses[$key] = 'yes';
        }

        return StudentExitInterview::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'student_info' => ['department_position' => 'Loans', 'total_hours' => '486', 'date_of_interview' => '2026-08-30'],
            'responses' => $responses,
            'submission_status' => $status,
            'submitted_at' => $status === 'draft' ? null : now(),
        ]);
    }

    public function test_a_coordinator_sees_only_in_scope_exit_interviews(): void
    {
        $mine = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($mine);
        $this->interviewFor($this->batchFor($mine, $coordinator), 'In Scope Student');

        $theirs = $this->programFor('BSBA-FM', 'CABM-B');
        $this->interviewFor($this->batchFor($theirs, $this->coordinatorFor($theirs)), 'Out Of Scope Student');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/coordinator/exit-interviews')->assertOk();

        $names = collect($response->json('interviews.data'))->pluck('student_name')->all();
        $this->assertSame(['In Scope Student'], $names);
    }

    public function test_opening_an_out_of_scope_interview_is_refused(): void
    {
        $mine = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($mine);

        $theirs = $this->programFor('BSBA-FM', 'CABM-B');
        $foreign = $this->interviewFor($this->batchFor($theirs, $this->coordinatorFor($theirs)), 'Someone Else');

        Sanctum::actingAs($coordinator);

        $this->getJson("/api/coordinator/exit-interviews/{$foreign->id}")->assertForbidden();
        $this->get("/api/coordinator/exit-interviews/{$foreign->id}/pdf")->assertForbidden();
        $this->putJson("/api/coordinator/exit-interviews/{$foreign->id}", ['compliance' => 'complete'])->assertForbidden();
    }

    public function test_the_detail_returns_every_answer_and_the_resolved_header(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $interview = $this->interviewFor($this->batchFor($program, $coordinator), 'Reads Fine');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson("/api/coordinator/exit-interviews/{$interview->id}")->assertOk();

        $response->assertJsonPath('responses.q14', 'Answer for q14.');
        $response->assertJsonPath('responses.q11_choice', 'yes');
        $response->assertJsonPath('header.student_name', 'Reads Fine');
        $response->assertJsonPath('header.total_hours', '486');
    }

    public function test_the_coordinator_section_saves_and_never_touches_the_answers(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $interview = $this->interviewFor($this->batchFor($program, $coordinator), 'Signed Off');

        Sanctum::actingAs($coordinator);

        $this->putJson("/api/coordinator/exit-interviews/{$interview->id}", [
            'compliance' => 'pending',
            'pending_detail' => 'Final narrative report still outstanding.',
            'remarks' => 'Endorsed once cleared.',
        ])->assertOk()->assertJsonPath('submission_status', 'reviewed');

        $interview->refresh();

        $this->assertSame('pending', $interview->coordinator_section['compliance']);
        $this->assertSame($coordinator->id, $interview->reviewed_by);
        $this->assertNotNull($interview->reviewed_at);
        // The student's own words are untouched.
        $this->assertSame('Answer for q1.', $interview->responses['q1']);
    }

    /**
     * The two signature lines print what the coordinator TYPES — the
     * coordinator's in Section A as written and in capitals on the signature
     * line, the dean's in capitals — not merely the account name on record.
     */
    public function test_the_signatory_names_the_coordinator_types_are_what_print(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $interview = $this->interviewFor($this->batchFor($program, $coordinator), 'Named Signatories');

        Sanctum::actingAs($coordinator);

        $this->putJson("/api/coordinator/exit-interviews/{$interview->id}", [
            'compliance' => 'complete',
            'coordinator_name' => '  Maria Antonnette B. Gulilat, MABM, LPT ',
            'dean_name' => 'Ma. Angelica B. Calunsag, MSA, CPA',
        ])->assertOk();

        $interview->refresh();

        $this->assertSame('Maria Antonnette B. Gulilat, MABM, LPT', $interview->coordinator_section['coordinator_name']);
        $this->assertSame('Ma. Angelica B. Calunsag, MSA, CPA', $interview->coordinator_section['dean_name']);

        $header = $this->headerOf($interview);

        $this->assertSame('Maria Antonnette B. Gulilat, MABM, LPT', $header['coordinator_name']);
        $this->assertSame('MARIA ANTONNETTE B. GULILAT, MABM, LPT', $header['coordinator_signature_name']);
        $this->assertSame('MA. ANGELICA B. CALUNSAG, MSA, CPA', $header['dean_name']);

        $printed = collect(Layout::for($interview->form())->placeSignatories($header)['texts'])->pluck('text')->all();
        $this->assertContains('MARIA ANTONNETTE B. GULILAT, MABM, LPT', $printed);
        $this->assertContains('MA. ANGELICA B. CALUNSAG, MSA, CPA', $printed);

        $this->get("/api/coordinator/exit-interviews/{$interview->id}/pdf")->assertOk();
    }

    /**
     * Both signature dates print empty, to be dated by hand when each person
     * signs — saving the review in the app is not signing the paper, so its
     * date is not printed there.
     */
    public function test_the_signature_dates_are_left_empty_even_once_reviewed(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $interview = $this->interviewFor($this->batchFor($program, $coordinator), 'Dated By Hand');

        Sanctum::actingAs($coordinator);

        $this->putJson("/api/coordinator/exit-interviews/{$interview->id}", ['compliance' => 'complete'])->assertOk();

        $interview->refresh();
        $this->assertNotNull($interview->reviewed_at);

        $header = $this->headerOf($interview);

        foreach (Layout::SIGNATORIES as $signatory) {
            $this->assertSame('', $header[$signatory['date']] ?? '', "{$signatory['date']} should print empty");
        }
    }

    public function test_blank_signatory_names_fall_back_to_the_names_on_record(): void
    {
        $program = $this->programFor('BSIT');
        $program->department->update(['dean_name' => 'Dr. Ana Reyes']);
        $coordinator = $this->coordinatorFor($program);
        $coordinator->update(['name' => 'Prof. Rico Montoya']);
        $interview = $this->interviewFor($this->batchFor($program, $coordinator), 'Falls Back');

        Sanctum::actingAs($coordinator);

        $this->putJson("/api/coordinator/exit-interviews/{$interview->id}", [
            'compliance' => 'complete',
            'coordinator_name' => '   ',
            'dean_name' => null,
        ])->assertOk();

        $interview->refresh();

        $this->assertNull($interview->coordinator_section['coordinator_name']);
        $this->assertNull($interview->coordinator_section['dean_name']);

        $header = $this->headerOf($interview);
        $this->assertSame('PROF. RICO MONTOYA', $header['coordinator_signature_name']);
        $this->assertSame('DR. ANA REYES', $header['dean_name']);
    }

    /**
     * A name is measured against the line it prints on, in the bold capitals
     * it prints in — refused rather than shrunk or cut off on the page.
     */
    public function test_a_signatory_name_too_long_for_its_printed_line_is_refused(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $interview = $this->interviewFor($this->batchFor($program, $coordinator), 'Long Names');

        Sanctum::actingAs($coordinator);

        $this->putJson("/api/coordinator/exit-interviews/{$interview->id}", [
            'compliance' => 'complete',
            'coordinator_name' => 'Maria Antonnette Bernadette Gulilat-Villanueva, MABM, MAEd, LPT',
            'dean_name' => str_repeat('A', 121),
        ])->assertStatus(422)->assertJsonValidationErrors(['coordinator_name', 'dean_name']);

        $this->assertNull($interview->fresh()->reviewed_at, 'a refused save writes nothing');
    }

    /**
     * The fields start from what this coordinator last saved on another
     * interview, so a cohort's names are typed once, not once per student;
     * `on_record` says what prints when a field is left blank.
     */
    public function test_the_detail_prefills_the_names_this_coordinator_last_saved(): void
    {
        $program = $this->programFor('BSIT');
        $program->department->update(['dean_name' => 'Dr. Ana Reyes']);
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);
        $first = $this->interviewFor($batch, 'First Reviewed');
        $second = $this->interviewFor($batch, 'Not Yet Reviewed');

        Sanctum::actingAs($coordinator);

        $this->getJson("/api/coordinator/exit-interviews/{$second->id}")
            ->assertOk()
            ->assertJsonPath('signatories.prefill.coordinator_name', $coordinator->name)
            ->assertJsonPath('signatories.prefill.dean_name', 'Dr. Ana Reyes');

        $this->putJson("/api/coordinator/exit-interviews/{$first->id}", [
            'compliance' => 'complete',
            'coordinator_name' => 'Maria Antonnette B. Gulilat, MABM, LPT',
            'dean_name' => 'Ma. Angelica B. Calunsag, MSA, CPA',
        ])->assertOk();

        $this->getJson("/api/coordinator/exit-interviews/{$second->id}")
            ->assertOk()
            ->assertJsonPath('signatories.prefill.coordinator_name', 'Maria Antonnette B. Gulilat, MABM, LPT')
            ->assertJsonPath('signatories.prefill.dean_name', 'Ma. Angelica B. Calunsag, MSA, CPA')
            ->assertJsonPath('signatories.on_record.coordinator_name', $coordinator->name)
            ->assertJsonPath('signatories.on_record.dean_name', 'Dr. Ana Reyes');
    }

    /**
     * @return array<string, string>
     */
    private function headerOf(StudentExitInterview $interview): array
    {
        $renderer = new class
        {
            use BuildsExitInterviewPdf;

            public function header(StudentExitInterview $interview): array
            {
                return $this->exitInterviewHeader($interview);
            }
        };

        return $renderer->header($interview);
    }

    public function test_a_draft_interview_cannot_be_signed_off(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $interview = $this->interviewFor($this->batchFor($program, $coordinator), 'Still Typing', 'draft');

        Sanctum::actingAs($coordinator);

        $this->putJson("/api/coordinator/exit-interviews/{$interview->id}", ['compliance' => 'complete'])
            ->assertStatus(422);
    }

    public function test_the_coordinator_downloads_the_same_long_bond_facsimile(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $interview = $this->interviewFor($this->batchFor($program, $coordinator), 'Printable Student');

        Sanctum::actingAs($coordinator);

        $pdf = $this->get("/api/coordinator/exit-interviews/{$interview->id}/pdf")->assertOk()->getContent();

        // 612 x 936pt is the Philippine long bond the reference is drawn on;
        // dompdf would otherwise default to A4 and shift every measured x.
        $this->assertStringContainsString('MediaBox [0.000 0.000 612.000 936.000]', $pdf);
        $this->assertStringContainsString('/Count 2', $pdf);
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);

        $this->interviewFor($batch, 'Submitted One', 'submitted');
        $this->interviewFor($batch, 'Draft One', 'draft');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/coordinator/exit-interviews?status=draft')->assertOk();

        $names = collect($response->json('interviews.data'))->pluck('student_name')->all();
        $this->assertSame(['Draft One'], $names);
    }
}
