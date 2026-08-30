<?php

namespace Tests\Feature\Student;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentExitInterview;
use App\Models\StudentInformationSheet;
use App\Models\User;
use App\Support\ExitInterviewFormLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The student's half of the CABM Internship Program Student Exit Interview
 * Form: draft, submit, lock, and the PDF.
 */
class ExitInterviewTest extends TestCase
{
    use RefreshDatabase;

    private function scaffold(string $status = 'active'): array
    {
        $department = Department::create(['code' => 'CABM-B', 'name' => 'CABM-B', 'is_active' => true, 'dean_name' => 'Dr. Ana Reyes']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSBA-FM', 'name' => 'BSBA Financial Management', 'is_active' => true]);
        $coordinator = User::factory()->create(['role' => 'coordinator', 'name' => 'Prof. Balbero']);
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

        $student = User::factory()->create(['role' => 'student', 'name' => 'Jomar Bactol', 'program_id' => $program->id]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company = Company::create(['name' => 'Tagbilaran Cooperative Bank', 'address' => 'CPG Ave', 'is_active' => true]);

        $enrollment = BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'assigned_division' => 'Loans Department',
            'status' => $status,
        ]);

        // The whole student route group sits behind `infosheet.approved`.
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
    private function completePayload(bool $submit = true): array
    {
        $responses = [];

        foreach (StudentExitInterview::QUESTION_KEYS as $key) {
            $responses[$key] = 'Answer for '.$key.'.';
        }

        foreach (StudentExitInterview::CHOICE_KEYS as $key) {
            $responses[$key] = 'yes';
        }

        return [
            'submit' => $submit,
            'student_info' => [
                'department_position' => 'Loans Department',
                'total_hours' => '486',
                'date_of_interview' => now()->toDateString(),
            ],
            'responses' => $responses,
        ];
    }

    public function test_the_form_offers_the_enrollment_it_belongs_to(): void
    {
        ['student' => $student] = $this->scaffold();
        Sanctum::actingAs($student);

        $response = $this->getJson('/api/student/exit-interview')->assertOk();

        $response->assertJsonPath('interview', null);
        $response->assertJsonPath('header.student_name', 'Jomar Bactol');
        $response->assertJsonPath('header.company', 'Tagbilaran Cooperative Bank');
        $response->assertJsonPath('header.coordinator_name', 'Prof. Balbero');
        $response->assertJsonPath('header.assigned_division', 'Loans Department');
        $response->assertJsonPath('answer_char_limits.q1', ExitInterviewFormLayout::charLimitFor('q1'));
        // Question 7 has four printed lines, not five.
        $response->assertJsonPath('answer_char_limits.q7', ExitInterviewFormLayout::charLimitFor('q7'));
    }

    public function test_a_draft_saves_with_nothing_filled_in(): void
    {
        ['student' => $student, 'batch' => $batch] = $this->scaffold();
        Sanctum::actingAs($student);

        $this->postJson('/api/student/exit-interview', [
            'submit' => false,
            'student_info' => ['department_position' => 'Loans'],
            'responses' => ['q1' => 'Only the first one so far.'],
        ])->assertOk()->assertJsonPath('interview.submission_status', 'draft');

        $this->assertDatabaseHas('student_exit_interviews', [
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'submission_status' => 'draft',
        ]);
    }

    public function test_a_submit_requires_every_question(): void
    {
        ['student' => $student] = $this->scaffold();
        Sanctum::actingAs($student);

        $this->postJson('/api/student/exit-interview', [
            'submit' => true,
            'student_info' => ['department_position' => 'Loans', 'date_of_interview' => now()->toDateString()],
            'responses' => ['q1' => 'Only the first one.'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['responses.q2', 'responses.q14', 'responses.q2_choice']);
    }

    public function test_an_answer_longer_than_its_printed_lines_is_refused(): void
    {
        ['student' => $student] = $this->scaffold();
        Sanctum::actingAs($student);

        $payload = $this->completePayload();
        $payload['responses']['q1'] = trim(str_repeat('A LONG SHOUTED ANSWER THAT WILL NOT FIT. ', 40));

        $this->postJson('/api/student/exit-interview', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['responses.q1']);
    }

    public function test_submitting_locks_the_form_and_notifies_the_coordinator(): void
    {
        ['student' => $student, 'coordinator' => $coordinator] = $this->scaffold();
        Sanctum::actingAs($student);

        $this->postJson('/api/student/exit-interview', $this->completePayload())
            ->assertOk()
            ->assertJsonPath('interview.submission_status', 'submitted');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $coordinator->id,
            'type' => 'in_app',
            'title' => 'Exit interview submitted',
        ]);

        // A second save — even a draft save — is refused.
        $this->postJson('/api/student/exit-interview', $this->completePayload(false))
            ->assertStatus(422);
    }

    /**
     * The deliberate deviation from the project-wide write rule: every other
     * student write endpoint requires an ACTIVE enrollment, but an exit
     * interview is by definition filed at the end of the placement.
     */
    public function test_a_completed_student_can_still_file_their_exit_interview(): void
    {
        ['student' => $student] = $this->scaffold('completed');
        Sanctum::actingAs($student);

        $this->getJson('/api/student/exit-interview')->assertOk()->assertJsonPath('ojt_completed', true);

        $this->postJson('/api/student/exit-interview', $this->completePayload())
            ->assertOk()
            ->assertJsonPath('interview.submission_status', 'submitted');
    }

    public function test_a_dropped_student_has_no_exit_interview_to_file(): void
    {
        ['student' => $student] = $this->scaffold('dropped');
        Sanctum::actingAs($student);

        $this->getJson('/api/student/exit-interview')->assertStatus(422);
        $this->postJson('/api/student/exit-interview', $this->completePayload())->assertStatus(422);
    }

    public function test_the_pdf_is_philippine_long_bond_and_exactly_two_pages(): void
    {
        ['student' => $student] = $this->scaffold();
        Sanctum::actingAs($student);

        $this->postJson('/api/student/exit-interview', $this->completePayload())->assertOk();

        $pdf = $this->get('/api/student/exit-interview/pdf')->assertOk()->getContent();

        // The reference form is 612 x 936pt (8.5" x 13"), NOT dompdf's A4
        // default. This assertion is what stops setPaper() being dropped.
        $this->assertStringContainsString('MediaBox [0.000 0.000 612.000 936.000]', $pdf);
        $this->assertStringContainsString('/Count 2', $pdf);
    }

    public function test_the_pdf_is_refused_before_the_form_is_started(): void
    {
        ['student' => $student] = $this->scaffold();
        Sanctum::actingAs($student);

        $this->get('/api/student/exit-interview/pdf')->assertStatus(404);
    }
}
