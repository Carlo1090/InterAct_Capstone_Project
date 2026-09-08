<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use App\Notifications\NewAccountCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Credential Manager replaced the per-row "Resend" button on
 * Users → Interns (moved to the profile popover, 2026-09-08). Two rules here
 * were NOT true of Resend and are the reason the move was worth making — do
 * not "restore" either:
 *
 *  - it reaches SUPERVISORS as well as interns, and
 *  - it ALWAYS returns the temporary password, including for an account with
 *    no email address at all, which Resend simply 422'd.
 */
class CredentialManagerTest extends TestCase
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

    /**
     * A supervisor is in a coordinator's scope by COMPANY, not by program —
     * and a company not yet linked to any enrollment is in every coordinator's
     * scope (there is no creator column on users), which is exactly the shape
     * a freshly-created supervisor has.
     */
    private function supervisorAt(string $companyName): User
    {
        $company = Company::create(['name' => $companyName, 'address' => 'Tagbilaran City, Bohol', 'is_active' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor', 'email' => 'sup@example.com']);

        CompanySupervisor::create([
            'company_id' => $company->id,
            'user_id' => $supervisor->id,
            'position' => 'Branch Manager',
        ]);

        return $supervisor;
    }

    public function test_the_list_covers_both_interns_and_supervisors_in_scope(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);

        $student = User::factory()->create(['role' => 'student', 'program_id' => $bsit->id, 'name' => 'Ana Reyes']);
        $supervisor = $this->supervisorAt('TechPH Inc.');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/credentials')->assertOk();

        $ids = collect($response->json('accounts'))->pluck('id');
        $this->assertTrue($ids->contains($student->id), 'the in-scope intern is missing');
        $this->assertTrue($ids->contains($supervisor->id), 'the in-scope supervisor is missing');

        $roles = collect($response->json('accounts'))->pluck('role')->unique()->sort()->values();
        $this->assertSame(['student', 'supervisor'], $roles->all());
    }

    public function test_the_list_excludes_an_out_of_scope_student(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);

        $otherProgram = $this->programFor('BSBA-FM', 'CABM-B');
        $outStudent = User::factory()->create(['role' => 'student', 'program_id' => $otherProgram->id]);

        Sanctum::actingAs($coordinator, ['*']);

        $ids = collect($this->getJson('/api/coordinator/credentials')->json('accounts'))->pluck('id');
        $this->assertFalse($ids->contains($outStudent->id));
    }

    public function test_the_list_can_be_narrowed_by_role_and_by_search(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);

        User::factory()->create(['role' => 'student', 'program_id' => $bsit->id, 'name' => 'Ana Reyes']);
        User::factory()->create(['role' => 'student', 'program_id' => $bsit->id, 'name' => 'Bruno Cruz']);
        $this->supervisorAt('TechPH Inc.');

        Sanctum::actingAs($coordinator, ['*']);

        $students = $this->getJson('/api/coordinator/credentials?role=student')->json('accounts');
        $this->assertSame(['student'], collect($students)->pluck('role')->unique()->all());

        $matched = $this->getJson('/api/coordinator/credentials?search=Bruno')->json('accounts');
        $this->assertCount(1, $matched);
        $this->assertSame('Bruno Cruz', $matched[0]['name']);
    }

    public function test_a_coordinator_issues_a_temporary_password_to_an_intern(): void
    {
        Notification::fake();

        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $student = User::factory()->create([
            'role' => 'student',
            'program_id' => $bsit->id,
            'email' => 'student@example.com',
            'username' => '2026-099',
        ]);
        $originalHash = $student->password;

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson("/api/coordinator/credentials/{$student->id}/issue")->assertOk();

        $this->assertTrue($response->json('emailed'));
        $this->assertNotEmpty($response->json('temporary_password'));

        $student->refresh();
        $this->assertNotSame($originalHash, $student->password);
        $this->assertTrue($student->must_change_password);

        Notification::assertSentTo($student, NewAccountCredentials::class);
    }

    public function test_a_coordinator_issues_a_temporary_password_to_a_supervisor(): void
    {
        Notification::fake();

        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $supervisor = $this->supervisorAt('TechPH Inc.');
        $originalHash = $supervisor->password;

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson("/api/coordinator/credentials/{$supervisor->id}/issue")->assertOk();

        $this->assertTrue($response->json('emailed'));
        $this->assertNotEmpty($response->json('temporary_password'));

        $supervisor->refresh();
        $this->assertNotSame($originalHash, $supervisor->password);
        $this->assertTrue($supervisor->must_change_password);

        Notification::assertSentTo($supervisor, NewAccountCredentials::class);
    }

    /**
     * The whole point of surfacing the password rather than only mailing it:
     * a manually-created student can have email = null, and the old Resend
     * action 422'd them with no way for a coordinator to help at all.
     */
    public function test_an_account_with_no_email_still_gets_a_password_to_read_out(): void
    {
        Notification::fake();

        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $student = User::factory()->create([
            'role' => 'student',
            'program_id' => $bsit->id,
            'email' => null,
            'username' => '2026-100',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson("/api/coordinator/credentials/{$student->id}/issue")->assertOk();

        $this->assertNull($response->json('emailed'), 'no address is a different fact from a failed send');
        $this->assertNotEmpty($response->json('temporary_password'));

        $this->assertTrue($student->refresh()->must_change_password);
        Notification::assertNothingSent();
    }

    public function test_an_out_of_scope_student_is_refused(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);

        $otherProgram = $this->programFor('BSBA-FM', 'CABM-B');
        $outStudent = User::factory()->create(['role' => 'student', 'program_id' => $otherProgram->id, 'email' => 'out@example.com']);

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson("/api/coordinator/credentials/{$outStudent->id}/issue")->assertStatus(403);
        $this->assertFalse($outStudent->refresh()->must_change_password);
    }

    /**
     * A supervisor's scope is the company, so one attached only to a company
     * already carrying another department's enrollments is out of reach.
     */
    public function test_an_out_of_scope_supervisor_is_refused(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);

        $otherProgram = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoordinator = $this->coordinatorFor($otherProgram);
        $farCompany = Company::create(['name' => 'Far Co.', 'address' => 'Tagbilaran City, Bohol', 'is_active' => true]);
        $farSupervisor = User::factory()->create(['role' => 'supervisor']);
        CompanySupervisor::create([
            'company_id' => $farCompany->id,
            'user_id' => $farSupervisor->id,
            'position' => 'Manager',
        ]);

        // Link the company to the OTHER department's cohort, so it stops being
        // an "unlinked, therefore visible to everyone" company.
        $batch = Batch::create([
            'program_id' => $otherProgram->id,
            'coordinator_id' => $otherCoordinator->id,
            'name' => 'BSBA-FM 2026 Internship',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
        ]);
        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => User::factory()->create(['role' => 'student', 'program_id' => $otherProgram->id])->id,
            'company_id' => $farCompany->id,
            'supervisor_id' => $farSupervisor->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson("/api/coordinator/credentials/{$farSupervisor->id}/issue")->assertStatus(403);
    }

    /**
     * A coordinator provisions students and supervisors only, so another
     * coordinator's (or the admin's) account is not merely out of scope — it
     * is not a kind of account this surface manages at all.
     */
    public function test_a_coordinator_account_is_not_a_credential_manager_target(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $peer = $this->coordinatorFor($bsit);

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson("/api/coordinator/credentials/{$peer->id}/issue")->assertStatus(404);
        $this->assertFalse($peer->refresh()->must_change_password);
    }

    public function test_a_student_cannot_reach_the_credential_manager(): void
    {
        $bsit = $this->programFor('BSIT');
        $student = User::factory()->create(['role' => 'student', 'program_id' => $bsit->id]);

        Sanctum::actingAs($student, ['*']);

        $this->getJson('/api/coordinator/credentials')->assertStatus(403);
    }
}
