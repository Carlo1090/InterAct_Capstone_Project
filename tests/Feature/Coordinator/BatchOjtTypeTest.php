<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use App\Services\DtrService;
use App\Services\EnrollmentService;
use App\Support\AuthUserPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The batch-level OJT type: is this cohort supervised by a company account, or
 * reviewed by the coordinator directly?
 *
 * The cases below each pin a rule that used to be unconditional, so none of
 * them is decoration: enrollment used to REQUIRE a company supervisor login
 * always, and the Daily Time Record used to turn on for anyone whose
 * coordinator had switched it on.
 */
class BatchOjtTypeTest extends TestCase
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

    private function batchFor(Program $program, User $coordinator, string $ojtType = Batch::OJT_TYPE_SUPERVISOR): Batch
    {
        return Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Batch '.uniqid(),
            'ojt_type' => $ojtType,
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

    /** A company with NO login-bearing supervisor — the case that used to 422. */
    private function companyWithoutSupervisor(): Company
    {
        return Company::create(['name' => 'Co '.uniqid(), 'address' => 'Addr', 'is_active' => true]);
    }

    private function companyWithSupervisor(?User $supervisor = null): Company
    {
        $company = $this->companyWithoutSupervisor();
        CompanySupervisor::create([
            'company_id' => $company->id,
            'user_id' => ($supervisor ?? User::factory()->create(['role' => 'supervisor']))->id,
            'position' => 'Supervisor',
        ]);

        return $company;
    }

    public function test_a_batch_created_without_an_ojt_type_is_supervisor_supported(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        Sanctum::actingAs($coordinator);

        $response = $this->postJson('/api/coordinator/batches', [
            'program_id' => $program->id,
            'name' => 'Legacy Payload Batch',
            'academic_year' => '2026',
            'semester' => 'Internship',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00',
        ]);

        $response->assertCreated();
        $this->assertSame(Batch::OJT_TYPE_SUPERVISOR, Batch::find($response->json('id'))->ojt_type);
    }

    public function test_a_coordinator_centered_batch_enrolls_at_a_company_with_no_supervisor_login(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $company = $this->companyWithoutSupervisor();

        $enrollment = app(EnrollmentService::class)
            ->enrollOrReactivate($batch->id, $student->id, $company->id);

        $this->assertSame('active', $enrollment->status);
        // The whole point: no supervisor exists, and none is invented.
        $this->assertNull($enrollment->supervisor_id);
        $this->assertNull($enrollment->company_supervisor_id);
    }

    public function test_a_supervisor_supported_batch_still_refuses_a_company_with_no_supervisor_login(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $company = $this->companyWithoutSupervisor();

        $this->expectExceptionMessage('This company has no supervisor account yet.');

        app(EnrollmentService::class)->enrollOrReactivate($batch->id, $student->id, $company->id);
    }

    public function test_the_ojt_type_is_frozen_once_an_intern_is_enrolled(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        app(EnrollmentService::class)
            ->enrollOrReactivate($batch->id, $student->id, $this->companyWithSupervisor()->id);

        Sanctum::actingAs($coordinator);

        $this->putJson("/api/coordinator/batches/{$batch->id}", [
            'ojt_type' => Batch::OJT_TYPE_COORDINATOR,
        ])->assertStatus(422)->assertJsonValidationErrors('ojt_type');

        $this->assertSame(Batch::OJT_TYPE_SUPERVISOR, $batch->fresh()->ojt_type);
    }

    public function test_the_ojt_type_is_editable_while_the_roster_is_still_empty(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);

        Sanctum::actingAs($coordinator);

        $this->putJson("/api/coordinator/batches/{$batch->id}", [
            'ojt_type' => Batch::OJT_TYPE_COORDINATOR,
        ])->assertOk();

        $this->assertSame(Batch::OJT_TYPE_COORDINATOR, $batch->fresh()->ojt_type);
    }

    /**
     * The batches page PUTs the whole form back, including fields the
     * coordinator never touched. Re-stating the SAME value on an enrolled
     * batch must not be read as an attempt to change it.
     */
    public function test_restating_the_same_ojt_type_on_an_enrolled_batch_is_allowed(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        app(EnrollmentService::class)
            ->enrollOrReactivate($batch->id, $student->id, $this->companyWithSupervisor()->id);

        Sanctum::actingAs($coordinator);

        $this->putJson("/api/coordinator/batches/{$batch->id}", [
            'name' => 'Renamed Batch',
            'ojt_type' => Batch::OJT_TYPE_SUPERVISOR,
        ])->assertOk();

        $this->assertSame('Renamed Batch', $batch->fresh()->name);
    }

    /**
     * The mixed-company case. Supervisor scoping is company-based, so before
     * the null-supervisor filter this intern appeared on the supervisor's own
     * roster despite the supervisor having no role over them at all.
     */
    public function test_a_coordinator_centered_intern_never_appears_on_a_supervisors_roster(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company = $this->companyWithSupervisor($supervisor);

        $supervisedBatch = $this->batchFor($program, $coordinator);
        $centeredBatch = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);

        $theirs = User::factory()->create(['role' => 'student', 'name' => 'Supervised Intern', 'program_id' => $program->id]);
        $notTheirs = User::factory()->create(['role' => 'student', 'name' => 'Centered Intern', 'program_id' => $program->id]);

        $service = app(EnrollmentService::class);
        $service->enrollOrReactivate($supervisedBatch->id, $theirs->id, $company->id);
        $service->enrollOrReactivate($centeredBatch->id, $notTheirs->id, $company->id);

        Sanctum::actingAs($supervisor);

        $response = $this->getJson('/api/supervisor/interns')->assertOk();

        $body = $response->getContent();
        $this->assertStringContainsString('Supervised Intern', $body);
        $this->assertStringNotContainsString('Centered Intern', $body);
    }

    public function test_the_daily_time_record_does_not_run_for_a_coordinator_centered_batch(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        // The coordinator's own preference is ON — the batch is what turns it off.
        $coordinator->forceFill(['dtr_enabled' => true])->save();

        $batch = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        app(EnrollmentService::class)
            ->enrollOrReactivate($batch->id, $student->id, $this->companyWithoutSupervisor()->id);

        $this->assertFalse(app(DtrService::class)->appliesTo($student->fresh()));

        // And the SPA is told the same thing, so the nav item never appears.
        $payload = AuthUserPayload::build($student->fresh());
        $this->assertFalse($payload->getAttribute('student_dtr_enabled'));
    }

    public function test_the_daily_time_record_still_runs_for_a_supervisor_supported_batch(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $coordinator->forceFill(['dtr_enabled' => true])->save();

        $batch = $this->batchFor($program, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        app(EnrollmentService::class)
            ->enrollOrReactivate($batch->id, $student->id, $this->companyWithSupervisor()->id);

        $this->assertTrue(app(DtrService::class)->appliesTo($student->fresh()));
        $this->assertTrue(AuthUserPayload::build($student->fresh())->getAttribute('student_dtr_enabled'));
    }

    public function test_an_existing_enrollment_switching_company_keeps_the_null_supervisor_on_a_centered_batch(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator, Batch::OJT_TYPE_COORDINATOR);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        $service = app(EnrollmentService::class);
        $service->enrollOrReactivate($batch->id, $student->id, $this->companyWithoutSupervisor()->id);

        // Re-placing at a company that DOES have a login must not silently pin
        // one: the batch, not the company, decides whether a supervisor exists.
        $withLogin = $this->companyWithSupervisor();
        $enrollment = $service->enrollOrReactivate($batch->id, $student->id, $withLogin->id);

        $this->assertSame($withLogin->id, $enrollment->company_id);
        $this->assertNull($enrollment->supervisor_id);
        $this->assertSame(1, BatchStudent::where('batch_id', $batch->id)->where('student_id', $student->id)->count());
    }
}
