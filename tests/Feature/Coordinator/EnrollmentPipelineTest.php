<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The path from a created account to a placement, as the coordinator sees it:
 * the intake stage on Users → Interns, the OJT type the Enroll form needs, and
 * the "On Accept" preview on a submitted information sheet.
 */
class EnrollmentPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Program $program;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $department = Department::create(['code' => 'CAST', 'name' => 'CAST Department', 'is_active' => true]);
        $this->program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BSIT Program', 'is_active' => true]);
        $this->coordinator = User::factory()->create(['role' => 'coordinator']);
        $this->coordinator->departmentsCoordinated()->attach($department->id);
    }

    private function batch(string $ojtType = Batch::OJT_TYPE_SUPERVISOR, string $name = 'Batch A'): Batch
    {
        return Batch::create([
            'program_id' => $this->program->id,
            'coordinator_id' => $this->coordinator->id,
            'name' => $name,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026',
            'semester' => 'Internship',
            'is_active' => true,
            'ojt_type' => $ojtType,
        ]);
    }

    private function company(bool $withLogin, string $name = 'TechPH Inc.'): Company
    {
        $company = Company::create(['name' => $name, 'address' => 'Tagbilaran', 'is_active' => true]);

        if ($withLogin) {
            $login = User::factory()->create(['role' => 'supervisor', 'name' => 'Rosa Lim']);
            CompanySupervisor::create(['company_id' => $company->id, 'user_id' => $login->id]);
        }

        return $company;
    }

    private function student(array $attributes = []): User
    {
        return User::factory()->create([
            'role' => 'student',
            'program_id' => $this->program->id,
            'must_change_password' => false,
            ...$attributes,
        ]);
    }

    private function sheet(User $student, Batch $batch, string $status, ?Company $company = null): StudentInformationSheet
    {
        return StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'submission_status' => $status,
            'submitted_at' => $status === 'draft' ? null : now(),
            'personal_info' => ['first_name' => 'X'],
            'academic_info' => [],
            'ojt_info' => $company ? ['company_id' => $company->id, 'host_company' => $company->name] : [],
        ]);
    }

    private function placement(User $student, Batch $batch, Company $company, string $status): BatchStudent
    {
        return BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $company->loginSupervisor?->user_id,
            'status' => $status,
        ]);
    }

    public function test_the_interns_list_reports_each_students_intake_stage(): void
    {
        $batch = $this->batch();
        $earlier = $this->batch(name: 'Batch Earlier');
        $company = $this->company(withLogin: true);

        $notSignedIn = $this->student(['name' => 'A Not Signed In', 'must_change_password' => true]);
        $this->sheet($notSignedIn, $batch, 'draft');

        $drafting = $this->student(['name' => 'B Drafting']);
        $this->sheet($drafting, $batch, 'draft');

        $submitted = $this->student(['name' => 'C Submitted']);
        $this->sheet($submitted, $batch, 'submitted', $company);

        $returned = $this->student(['name' => 'D Returned']);
        $this->sheet($returned, $batch, 'rejected', $company);

        $enrolled = $this->student(['name' => 'E Enrolled']);
        $this->sheet($enrolled, $batch, 'approved', $company);
        $this->placement($enrolled, $batch, $company, 'active');

        // A finished intern used to read NOT ENROLLED: there is no active row.
        $completed = $this->student(['name' => 'F Completed']);
        $this->sheet($completed, $earlier, 'approved', $company);
        $this->placement($completed, $earlier, $company, 'completed');

        $dropped = $this->student(['name' => 'G Dropped']);
        $this->sheet($dropped, $batch, 'approved', $company);
        $this->placement($dropped, $batch, $company, 'dropped');

        Sanctum::actingAs($this->coordinator, ['*']);

        $rows = collect($this->getJson('/api/coordinator/users/interns')->assertOk()->json())->keyBy('name');

        $this->assertSame('not_signed_in', $rows['A Not Signed In']['stage']);
        $this->assertSame('drafting', $rows['B Drafting']['stage']);
        $this->assertSame('submitted', $rows['C Submitted']['stage']);
        $this->assertSame('returned', $rows['D Returned']['stage']);
        $this->assertSame('enrolled', $rows['E Enrolled']['stage']);
        $this->assertSame('completed', $rows['F Completed']['stage']);
        $this->assertSame('dropped', $rows['G Dropped']['stage']);

        // The batch each stage is about — the intended one before placement.
        $this->assertSame('Batch A', $rows['A Not Signed In']['stage_batch']['name']);
        $this->assertSame('Batch Earlier', $rows['F Completed']['stage_batch']['name']);

        // Unchanged for existing callers.
        $this->assertTrue($rows['E Enrolled']['enrolled']);
        $this->assertFalse($rows['F Completed']['enrolled']);
    }

    public function test_enrollment_options_say_which_batches_are_coordinator_centered(): void
    {
        $supported = $this->batch(Batch::OJT_TYPE_SUPERVISOR, 'Supported');
        $centered = $this->batch(Batch::OJT_TYPE_COORDINATOR, 'Centered');

        Sanctum::actingAs($this->coordinator, ['*']);

        $batches = collect($this->getJson('/api/coordinator/enrollment-options')->assertOk()->json('batches'))->keyBy('id');

        $this->assertSame('supervisor', $batches[$supported->id]['ojt_type']);
        $this->assertSame('coordinator', $batches[$centered->id]['ojt_type']);
    }

    public function test_the_review_previews_the_placement_accept_will_create(): void
    {
        $batch = $this->batch();
        $company = $this->company(withLogin: true);
        $student = $this->student();
        $this->sheet($student, $batch, 'submitted', $company);

        Sanctum::actingAs($this->coordinator, ['*']);

        $placement = $this->getJson("/api/coordinator/info-sheets/{$student->id}")->assertOk()->json('placement');

        $this->assertSame('Batch A', $placement['batch']['name']);
        $this->assertSame('BSIT', $placement['batch']['program']);
        $this->assertSame('TechPH Inc.', $placement['company']['name']);
        $this->assertSame('Rosa Lim', $placement['supervisor']['name']);
        $this->assertFalse($placement['coordinator_centered']);
        $this->assertNull($placement['blocker']);
    }

    /**
     * The warning shown before Accept must be the very refusal Accept gives.
     */
    public function test_a_company_without_a_login_is_flagged_before_accept_with_accepts_own_message(): void
    {
        $batch = $this->batch();
        $company = $this->company(withLogin: false);
        $student = $this->student();
        $this->sheet($student, $batch, 'submitted', $company);

        Sanctum::actingAs($this->coordinator, ['*']);

        $blocker = $this->getJson("/api/coordinator/info-sheets/{$student->id}")->json('placement.blocker');
        $this->assertNotNull($blocker);

        $accept = $this->postJson("/api/coordinator/info-sheets/{$student->id}/accept");
        $accept->assertStatus(422);
        $this->assertSame($blocker, $accept->json('message'));
    }

    public function test_a_coordinator_centered_batch_needs_no_company_login(): void
    {
        $batch = $this->batch(Batch::OJT_TYPE_COORDINATOR);
        $company = $this->company(withLogin: false);
        $student = $this->student();
        $this->sheet($student, $batch, 'submitted', $company);

        Sanctum::actingAs($this->coordinator, ['*']);

        $placement = $this->getJson("/api/coordinator/info-sheets/{$student->id}")->json('placement');

        $this->assertTrue($placement['coordinator_centered']);
        $this->assertNull($placement['supervisor']);
        $this->assertNull($placement['blocker']);

        $this->postJson("/api/coordinator/info-sheets/{$student->id}/accept")->assertOk();
    }

    public function test_a_student_active_elsewhere_is_flagged_before_accept(): void
    {
        $intended = $this->batch(name: 'Intended');
        $other = $this->batch(name: 'Other Cohort');
        $company = $this->company(withLogin: true);
        $student = $this->student();
        $this->placement($student, $other, $company, 'active');
        $this->sheet($student, $intended, 'submitted', $company);

        Sanctum::actingAs($this->coordinator, ['*']);

        $blocker = $this->getJson("/api/coordinator/info-sheets/{$student->id}")->json('placement.blocker');

        $this->assertStringContainsString('Other Cohort', (string) $blocker);
        $this->assertSame($blocker, $this->postJson("/api/coordinator/info-sheets/{$student->id}/accept")->assertStatus(422)->json('message'));
    }

    public function test_a_sheet_without_a_company_is_flagged_before_accept(): void
    {
        $batch = $this->batch();
        $student = $this->student();
        $this->sheet($student, $batch, 'submitted');

        Sanctum::actingAs($this->coordinator, ['*']);

        $placement = $this->getJson("/api/coordinator/info-sheets/{$student->id}")->json('placement');

        $this->assertNull($placement['company']);
        $this->assertSame('The student has not selected a valid company on their sheet.', $placement['blocker']);
    }
}
