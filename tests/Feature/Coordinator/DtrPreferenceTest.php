<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The coordinator's own DTR on/off switch, and the reason that explains an
 * "off". The switch itself predates this test file; what is pinned here is the
 * reason's lifecycle, because the whole value of recording WHY a programme
 * opted out is lost if a stale reason can outlive the decision.
 */
class DtrPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private Batch $batch;

    private Company $company;

    private int $supervisorId;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $department = Department::create(['name' => 'CAST', 'code' => 'CAST', 'is_active' => true]);
        $this->program = Program::create([
            'department_id' => $department->id,
            'name' => 'BS Information Technology',
            'code' => 'BSIT',
            'is_active' => true,
        ]);

        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'dtr_enabled' => true]);
        $this->coordinator->departmentsCoordinated()->attach($department->id);

        $this->batch = Batch::create([
            'program_id' => $this->program->id,
            'coordinator_id' => $this->coordinator->id,
            'name' => 'Batch 2026',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(3),
            'required_hours' => 500,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026-2027',
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        $this->company = Company::create(['name' => 'Acme Corp', 'address' => 'Tagbilaran', 'is_active' => true]);
        $this->supervisorId = User::factory()->create(['role' => 'supervisor'])->id;
    }

    private function enrolIntern(): User
    {
        $student = User::factory()->create(['role' => 'student', 'program_id' => $this->program->id]);

        BatchStudent::create([
            'batch_id' => $this->batch->id,
            'student_id' => $student->id,
            'company_id' => $this->company->id,
            'supervisor_id' => $this->supervisorId,
            'status' => 'active',
        ]);

        return $student;
    }

    /**
     * The switch governs SUPERVISOR-SUPPORTED cohorts only, so the count it
     * reports must too. `DtrService::runsForEnrollment()` needs both this
     * preference and a supervisor-supported batch, so a coordinator-centered
     * intern can never clock in whatever the switch says — counting them made
     * the turn-off confirmation claim that interns would "stop seeing it" when
     * they never had it. Real, not hypothetical: the seeded mdcbalbero
     * department reported 16 against 13 who can actually clock in.
     */
    public function test_the_affected_count_ignores_coordinator_centered_interns(): void
    {
        $this->enrolIntern();

        $centered = Batch::create([
            'program_id' => $this->program->id,
            'coordinator_id' => $this->coordinator->id,
            'name' => 'Field Placement 2026',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(3),
            'required_hours' => 500,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026-2027',
            'semester' => 'Internship',
            'ojt_type' => 'coordinator',
            'is_active' => true,
        ]);

        BatchStudent::create([
            'batch_id' => $centered->id,
            'student_id' => User::factory()->create(['role' => 'student', 'program_id' => $this->program->id])->id,
            'company_id' => $this->company->id,
            'supervisor_id' => null,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->coordinator);

        $this->getJson('/api/coordinator/dtr-preference')
            ->assertOk()
            ->assertJsonPath('affected_students', 1);
    }

    public function test_show_returns_the_preference_with_its_reason_vocabulary(): void
    {
        $this->enrolIntern();
        $this->enrolIntern();

        Sanctum::actingAs($this->coordinator);

        $response = $this->getJson('/api/coordinator/dtr-preference')->assertOk();

        $response->assertJson([
            'dtr_enabled' => true,
            'dtr_disabled_reason' => null,
            'dtr_disabled_note' => null,
            'affected_students' => 2,
        ]);

        // The select's options come from the server so they cannot drift out
        // of step with the Rule::in that validates them.
        $this->assertSame(
            array_keys(User::DTR_DISABLED_REASONS),
            array_column($response->json('reason_options'), 'value'),
        );
    }

    public function test_turning_it_off_stores_the_reason_and_note(): void
    {
        Sanctum::actingAs($this->coordinator);

        $this->putJson('/api/coordinator/dtr-preference', [
            'dtr_enabled' => false,
            'dtr_disabled_reason' => 'rotating_assignments',
            'dtr_disabled_note' => 'BSTM interns rotate across three resort branches each month.',
        ])->assertOk()->assertJson([
            'dtr_enabled' => false,
            'dtr_disabled_reason' => 'rotating_assignments',
            'dtr_disabled_note' => 'BSTM interns rotate across three resort branches each month.',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->coordinator->id,
            'dtr_enabled' => false,
            'dtr_disabled_reason' => 'rotating_assignments',
        ]);

        // The audit trail names the reason, so an admin reading the log alone
        // does not have to go and look up the column.
        $this->assertStringContainsString(
            'Interns rotate across several sites',
            SystemLog::where('user_id', $this->coordinator->id)->latest('logged_at')->value('description'),
        );
    }

    /**
     * The reason justifies an OFF switch. Left behind across a re-enable it
     * would sit on the record asserting a justification for a state the
     * programme is no longer in.
     */
    public function test_turning_it_back_on_clears_the_reason_and_note(): void
    {
        $this->coordinator->forceFill([
            'dtr_enabled' => false,
            'dtr_disabled_reason' => 'no_fixed_workplace',
            'dtr_disabled_note' => 'Field work only.',
        ])->save();

        Sanctum::actingAs($this->coordinator);

        $this->putJson('/api/coordinator/dtr-preference', [
            'dtr_enabled' => true,
            // Deliberately still sent: the client re-posts whatever is in the
            // form, and the server must not take it at face value.
            'dtr_disabled_reason' => 'no_fixed_workplace',
            'dtr_disabled_note' => 'Field work only.',
        ])->assertOk()->assertJson([
            'dtr_enabled' => true,
            'dtr_disabled_reason' => null,
            'dtr_disabled_note' => null,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $this->coordinator->id,
            'dtr_enabled' => true,
            'dtr_disabled_reason' => null,
            'dtr_disabled_note' => null,
        ]);
    }

    public function test_a_reason_outside_the_vocabulary_is_rejected(): void
    {
        Sanctum::actingAs($this->coordinator);

        $this->putJson('/api/coordinator/dtr-preference', [
            'dtr_enabled' => false,
            'dtr_disabled_reason' => 'because_i_said_so',
        ])->assertStatus(422)->assertJsonValidationErrors('dtr_disabled_reason');
    }

    /**
     * A coordinator is never blocked from switching the DTR off just because
     * they have not explained themselves yet — the explanation is a courtesy
     * to the next reader, not a gate on the decision.
     */
    public function test_the_reason_is_optional(): void
    {
        Sanctum::actingAs($this->coordinator);

        $this->putJson('/api/coordinator/dtr-preference', ['dtr_enabled' => false])
            ->assertOk()
            ->assertJson(['dtr_enabled' => false, 'dtr_disabled_reason' => null]);
    }

    /**
     * users.updated_at moves on any profile edit, so the "last changed" stamp
     * reads the audit trail instead — otherwise an avatar upload would re-date
     * a preference nobody touched.
     */
    public function test_the_last_changed_stamp_comes_from_the_audit_trail(): void
    {
        Sanctum::actingAs($this->coordinator);

        $this->assertNull($this->getJson('/api/coordinator/dtr-preference')->json('updated_at'));

        $this->putJson('/api/coordinator/dtr-preference', ['dtr_enabled' => false])->assertOk();

        $this->assertNotNull($this->getJson('/api/coordinator/dtr-preference')->json('updated_at'));
    }

    public function test_a_student_cannot_read_or_write_the_preference(): void
    {
        Sanctum::actingAs($this->enrolIntern());

        $this->getJson('/api/coordinator/dtr-preference')->assertStatus(403);
        $this->putJson('/api/coordinator/dtr-preference', ['dtr_enabled' => true])->assertStatus(403);
    }
}
