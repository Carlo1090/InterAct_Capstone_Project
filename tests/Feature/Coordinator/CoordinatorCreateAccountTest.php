<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoordinatorCreateAccountTest extends TestCase
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
            'name' => $program->code.' 2026 Internship',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
        ]);
    }

    public function test_coordinator_creates_student_account_with_profile_and_intended_draft_sheet(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/accounts', [
            'role' => 'student',
            'first_name' => 'New',
            'middle_name' => 'Q',
            'last_name' => 'Student',
            'username' => 'new.student',
            'password' => 'password123',
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('role', 'student');
        $response->assertJsonPath('username', 'new.student');

        $student = User::where('username', 'new.student')->first();
        $this->assertNotNull($student);
        $this->assertSame('New Q Student', $student->name);
        $this->assertSame('student', $student->role);
        $this->assertNull($student->email);
        $this->assertTrue($student->is_active);
        $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id]);

        // Intended placement recorded as a DRAFT sheet pointing at the batch...
        $this->assertDatabaseHas('student_information_sheets', [
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'submission_status' => 'draft',
        ]);

        // ...with the name split into the SAME parts the coordinator typed —
        // the middle name must NOT bleed into the family name (regression: the
        // scaffold used to re-split the joined users.name and produce
        // last_name = "Q Student").
        $sheet = StudentInformationSheet::where('student_id', $student->id)->first();
        $this->assertSame('Student', $sheet->personal_info['last_name']);
        $this->assertSame('New', $sheet->personal_info['first_name']);
        $this->assertSame('Q', $sheet->personal_info['middle_name']);
        $this->assertSame('Q', $student->studentProfile->middle_name);

        // ...but NOT yet enrolled — no batch_students row exists.
        $this->assertDatabaseMissing('batch_students', ['student_id' => $student->id]);
    }

    public function test_student_batch_must_belong_to_the_selected_program(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $otherProgram = $this->programFor('BSIT-X', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $wrongBatch = $this->batchFor($otherProgram, $coordinator);

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/coordinator/accounts', [
            'role' => 'student',
            'first_name' => 'Mismatch',
            'last_name' => 'User',
            'username' => 'mismatch.user',
            'password' => 'password123',
            'program_id' => $bsit->id,
            'batch_id' => $wrongBatch->id,
        ])->assertStatus(422);

        $this->assertNull(User::where('username', 'mismatch.user')->first());
    }

    public function test_coordinator_creates_supervisor_account(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/accounts', [
            'role' => 'supervisor',
            'first_name' => 'New',
            'last_name' => 'Supervisor',
            'username' => 'new.supervisor',
            'password' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('role', 'supervisor');

        $supervisor = User::where('username', 'new.supervisor')->first();
        $this->assertSame('supervisor', $supervisor->role);
        $this->assertTrue($supervisor->is_active);
        $this->assertTrue($supervisor->must_change_password);
        $this->assertDatabaseMissing('student_profiles', ['user_id' => $supervisor->id]);
    }

    public function test_coordinator_cannot_create_coordinator_or_admin(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        Sanctum::actingAs($coordinator, ['*']);

        foreach (['coordinator', 'admin'] as $role) {
            $this->postJson('/api/coordinator/accounts', [
                'role' => $role,
                'first_name' => 'Nope',
                'last_name' => 'User',
                'username' => "nope.{$role}",
                'password' => 'password123',
            ])->assertStatus(422)->assertJsonValidationErrors('role');
        }

        $this->assertSame(0, User::where('role', 'admin')->where('username', 'like', 'nope.%')->count());
    }

    public function test_student_batch_must_be_inside_the_coordinators_department(): void
    {
        // The batch is the real scope boundary for a student: it must belong to
        // this coordinator's department. A batch in another department is
        // rejected (a colleague's batch in the SAME department is allowed — see
        // the next test).
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        $otherProgram = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoordinator = $this->coordinatorFor($otherProgram);
        $foreignBatch = $this->batchFor($otherProgram, $otherCoordinator);

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/coordinator/accounts', [
            'role' => 'student',
            'first_name' => 'Foreign',
            'last_name' => 'Batch',
            'username' => 'foreign.batch',
            'password' => 'password123',
            'program_id' => $otherProgram->id,
            'batch_id' => $foreignBatch->id,
        ])->assertStatus(422)->assertJsonValidationErrors('batch_id');

        $this->assertNull(User::where('username', 'foreign.batch')->first());
    }

    /**
     * Create Account, Bulk Import and Enroll used to be limited to the
     * coordinator's OWN batches while Accept and the batch roster were
     * department-wide — so a coordinator covering for a colleague could enroll
     * a student into that colleague's batch but not create their account.
     */
    public function test_a_department_colleagues_batch_is_accepted(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $colleaguesBatch = $this->batchFor($bsit, $this->coordinatorFor($bsit));

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/coordinator/accounts', [
            'role' => 'student',
            'first_name' => 'Covered',
            'last_name' => 'Student',
            'password' => 'password123',
            'program_id' => $bsit->id,
            'batch_id' => $colleaguesBatch->id,
        ])->assertCreated();

        // The options endpoint offers it too, labelled with whose batch it is.
        $offered = collect($this->getJson('/api/coordinator/enrollment-options')->assertOk()->json('batches'))
            ->firstWhere('id', $colleaguesBatch->id);
        $this->assertNotNull($offered);
        $this->assertSame($colleaguesBatch->coordinator->name, $offered['coordinator_name']);
    }

    /**
     * The coordinator chose this password and handed it over — for a student
     * it is the predictable {first three letters}_{ID} suggestion unless
     * edited — so it must be replaced on first sign-in, as bulk import, the
     * Credential Manager and the admin's Create User already required.
     */
    public function test_a_created_account_must_change_its_password_and_is_audited(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        Sanctum::actingAs($coordinator, ['*']);

        $id = $this->postJson('/api/coordinator/accounts', [
            'role' => 'student',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'password' => 'ANA_2026-001',
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
            'student_id_number' => '2026-001',
        ])->assertCreated()->json('id');

        $this->assertTrue(User::find($id)->must_change_password);
        $this->assertDatabaseHas('system_logs', ['user_id' => $coordinator->id, 'action' => 'Account Created']);
    }

    /**
     * Each part may be 100 characters, but users.name holds the JOINED name
     * in 150 — MySQL's strict mode answered three long parts with a 500.
     */
    public function test_a_full_name_longer_than_the_column_is_refused_with_a_message(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/coordinator/accounts', [
            'role' => 'student',
            'first_name' => str_repeat('A', 60),
            'middle_name' => str_repeat('B', 60),
            'last_name' => str_repeat('C', 60),
            'password' => 'password123',
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ])->assertStatus(422)->assertJsonValidationErrors('last_name');

        $this->assertSame(0, User::where('role', 'student')->count());
    }

    public function test_username_is_auto_generated_from_name_when_omitted(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/accounts', [
            'role' => 'student',
            'first_name' => 'Auto',
            'last_name' => 'Generated',
            'password' => 'password123',
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ]);

        $response->assertCreated();
        $student = User::where('name', 'Auto Generated')->first();
        $this->assertNotNull($student);
        $this->assertNotEmpty($student->username);
        $this->assertSame($student->username, $response->json('username'));
    }

    public function test_coordinator_permanently_deletes_an_empty_student_account(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $bsit->id]);

        Sanctum::actingAs($coordinator, ['*']);

        $this->deleteJson("/api/coordinator/users/interns/{$student->id}")
            ->assertOk()
            ->assertJson(['deleted' => true]);

        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseMissing('student_profiles', ['user_id' => $student->id]);
    }

    public function test_coordinator_cannot_delete_a_student_with_journal_history(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $bsit->id]);

        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'entry_date' => now()->toDateString(),
            'content' => ['daily_accomplishment' => 'Worked on tickets.'],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $this->deleteJson("/api/coordinator/users/interns/{$student->id}")->assertStatus(422);

        // Account (and its history) survive.
        $this->assertDatabaseHas('users', ['id' => $student->id]);
        $this->assertDatabaseHas('journal_entries', ['student_id' => $student->id]);
    }

    public function test_coordinator_cannot_delete_an_out_of_scope_student(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        $otherProgram = $this->programFor('BSBA-FM', 'CABM-B');
        $outStudent = User::factory()->create(['role' => 'student', 'program_id' => $otherProgram->id]);

        Sanctum::actingAs($coordinator, ['*']);

        $this->deleteJson("/api/coordinator/users/interns/{$outStudent->id}")->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $outStudent->id]);
    }

    public function test_username_is_required_and_unique(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        User::factory()->create(['username' => 'taken.name']);

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/coordinator/accounts', [
            'role' => 'student',
            'first_name' => 'Dupe',
            'last_name' => 'User',
            'username' => 'taken.name',
            'password' => 'password123',
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ])->assertStatus(422)->assertJsonValidationErrors('username');
    }
}
