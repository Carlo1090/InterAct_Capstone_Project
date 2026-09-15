<?php

namespace Tests\Feature\Student;

use App\Http\Controllers\Concerns\BuildsExitInterviewPdf;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentExitInterview;
use App\Models\StudentInformationSheet;
use App\Models\User;
use App\Support\ExitInterview\ExitInterviewForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A department's exit interview form is the one its department is assigned
 * (departments.exit_interview_form), chosen by the admin — and a student on a
 * CAST batch answers the CAST questions, on the same template, with the same
 * rules, and downloads a PDF paginated for a form more than twice as long.
 *
 * Every rule here is one that the original single-form implementation could
 * not have got wrong, which is why each is pinned now.
 */
class CastExitInterviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function scaffold(string $formKey = 'cast', string $status = 'active'): array
    {
        $department = Department::create(['code' => 'CAST', 'name' => 'College of Arts, Sciences and Technology', 'is_active' => true, 'exit_interview_form' => $formKey]);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BSIT', 'is_active' => true]);
        $coordinator = User::factory()->create(['role' => 'coordinator', 'name' => 'Prof. Montoya']);
        $coordinator->departmentsCoordinated()->attach($department->id);

        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Batch A',
            'start_date' => now()->subMonths(3),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026',
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        $student = User::factory()->create(['role' => 'student', 'name' => 'Juan Dela Cruz', 'program_id' => $program->id]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company = Company::create(['name' => 'TechPH Inc.', 'address' => 'Tagbilaran', 'is_active' => true]);

        $enrollment = BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'assigned_division' => 'Software Development',
            'status' => $status,
        ]);

        StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'personal_info' => [],
            'academic_info' => [],
            'ojt_info' => [],
            'submission_status' => 'approved',
        ]);

        return compact('department', 'program', 'coordinator', 'batch', 'student', 'company', 'enrollment');
    }

    /**
     * @return array<string, mixed>
     */
    private function completeCastPayload(bool $submit = true, array $overrides = []): array
    {
        $form = ExitInterviewForms::get('cast');
        $responses = [];

        foreach ($form->questions() as $question) {
            $responses[$question['key']] = $question['type'] === 'scale' ? 'good' : 'Answer for '.$question['key'].'.';
        }

        return [
            'submit' => $submit,
            'student_info' => [
                'department_position' => 'Software Development',
                'total_hours' => '486',
                'date_of_interview' => now()->toDateString(),
            ],
            'responses' => array_replace($responses, $overrides),
        ];
    }

    public function test_a_cast_student_is_offered_the_cast_form(): void
    {
        ['student' => $student] = $this->scaffold();
        Sanctum::actingAs($student);

        $response = $this->getJson('/api/student/exit-interview')->assertOk();

        $response->assertJsonPath('form.key', 'cast');
        $response->assertJsonPath('form.question_count', 31);
        $response->assertJsonPath('form.student_info_heading', 'Student Information');
        $response->assertJsonPath('form.sections.0.heading', 'A. OJT Experience');
        // The paper's own numbering, gaps and all.
        $response->assertJsonPath('form.sections.4.questions.0.n', 28);
        $response->assertJsonPath('form.sections.4.questions.5.type', 'scale');
        $response->assertJsonPath('form.sections.4.questions.5.options.very_good', 'Very Good');
        // No answer lines for the rating question, so no character cap either.
        $this->assertArrayNotHasKey('q33', $response->json('answer_char_limits'));
        $this->assertArrayHasKey('q37', $response->json('answer_char_limits'));
    }

    /**
     * The department's assignment is what decides — the same batch, with the
     * department pointed at the CABM form, serves the CABM questions.
     */
    public function test_the_form_follows_the_departments_assignment(): void
    {
        ['student' => $student] = $this->scaffold('cabm');
        Sanctum::actingAs($student);

        $this->getJson('/api/student/exit-interview')
            ->assertOk()
            ->assertJsonPath('form.key', 'cabm')
            ->assertJsonPath('form.question_count', 14);
    }

    public function test_a_submit_is_validated_against_the_cast_questions(): void
    {
        ['student' => $student] = $this->scaffold();
        Sanctum::actingAs($student);

        // Every CAST question answered → accepted, and the row snapshots the form.
        $this->postJson('/api/student/exit-interview', $this->completeCastPayload())
            ->assertOk()
            ->assertJsonPath('interview.submission_status', 'submitted')
            ->assertJsonPath('interview.form_key', 'cast');

        $this->assertSame('cast', StudentExitInterview::sole()->form_key);
    }

    public function test_a_submit_missing_a_cast_question_is_refused(): void
    {
        ['student' => $student] = $this->scaffold();
        Sanctum::actingAs($student);

        // q37 exists on CAST, not CABM; q14 exists on both but a CABM-shaped
        // payload stops there.
        $this->postJson('/api/student/exit-interview', $this->completeCastPayload(true, ['q37' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['responses.q37']);
    }

    public function test_the_rating_question_only_accepts_its_own_options(): void
    {
        ['student' => $student] = $this->scaffold();
        Sanctum::actingAs($student);

        $this->postJson('/api/student/exit-interview', $this->completeCastPayload(true, ['q33' => 'amazing']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['responses.q33']);

        $this->postJson('/api/student/exit-interview', $this->completeCastPayload(true, ['q33' => 'poor']))
            ->assertOk();
    }

    /**
     * A CABM-shaped payload (with its q2_choice etc.) is not a CAST answer
     * set: the choice keys are simply not rules on this form, and the
     * missing CAST questions are.
     */
    public function test_cabm_yes_no_choices_mean_nothing_on_the_cast_form(): void
    {
        ['student' => $student] = $this->scaffold();
        Sanctum::actingAs($student);

        $payload = $this->completeCastPayload(true);
        $payload['responses']['q2_choice'] = 'yes';

        $this->postJson('/api/student/exit-interview', $payload)->assertOk();

        $this->assertArrayNotHasKey('q2_choice', StudentExitInterview::sole()->responses);
    }

    /**
     * An interview keeps the form it was BEGUN under, even if the admin
     * re-points the department afterwards — half a form's answers must never
     * be re-read as another form's.
     */
    public function test_an_interview_keeps_its_own_form_when_the_department_changes(): void
    {
        ['student' => $student, 'department' => $department] = $this->scaffold();
        Sanctum::actingAs($student);

        $this->postJson('/api/student/exit-interview', $this->completeCastPayload(false))->assertOk();

        $department->update(['exit_interview_form' => 'cabm']);

        $this->getJson('/api/student/exit-interview')
            ->assertOk()
            ->assertJsonPath('form.key', 'cast');

        // And a save is still validated as CAST: a CABM payload is refused.
        $this->postJson('/api/student/exit-interview', $this->completeCastPayload(true, ['q35' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['responses.q35']);
    }

    public function test_the_cast_pdf_is_long_bond_and_paginated_by_the_layout(): void
    {
        ['student' => $student] = $this->scaffold();
        Sanctum::actingAs($student);

        $this->postJson('/api/student/exit-interview', $this->completeCastPayload())->assertOk();

        $response = $this->get('/api/student/exit-interview/pdf');
        $response->assertOk();

        $pdf = $response->getContent();

        $this->assertStringContainsString('MediaBox [0.000 0.000 612.000 936.000]', $pdf);
        $this->assertStringContainsString('/Count 4', $pdf, 'the 31-question form paginates onto four long-bond pages');
        $this->assertStringNotContainsString('/Count 2', $pdf);
    }

    /**
     * The reference form's three pre-filled names belong to CABM. A CAST
     * department with no dean on record prints a blank, never the business
     * department's dean.
     */
    public function test_the_cabm_reference_names_never_print_on_a_cast_form(): void
    {
        ['student' => $student, 'batch' => $batch] = $this->scaffold();
        Sanctum::actingAs($student);

        $this->postJson('/api/student/exit-interview', $this->completeCastPayload())->assertOk();

        $interview = StudentExitInterview::sole();

        $renderer = new class
        {
            use BuildsExitInterviewPdf;

            public function header(StudentExitInterview $interview): array
            {
                return $this->exitInterviewHeader($interview);
            }
        };

        $header = $renderer->header($interview);

        $this->assertSame('', $header['dean_name']);
        $this->assertSame('Prof. Montoya', $header['coordinator_name']);
    }
}
