<?php

namespace Tests\Feature\Coordinator;

use App\Models\Department;
use App\Models\Program;
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

    public function test_coordinator_creates_student_account_with_profile(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/accounts', [
            'role' => 'student',
            'name' => 'New Student',
            'email' => 'newstudent@example.com',
            'password' => 'password123',
            'program_id' => $bsit->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('role', 'student');

        $student = User::where('email', 'newstudent@example.com')->first();
        $this->assertNotNull($student);
        $this->assertSame('student', $student->role);
        $this->assertTrue($student->is_active);
        $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id]);
    }

    public function test_coordinator_creates_supervisor_account(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/accounts', [
            'role' => 'supervisor',
            'name' => 'New Supervisor',
            'email' => 'newsup@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('role', 'supervisor');

        $supervisor = User::where('email', 'newsup@example.com')->first();
        $this->assertSame('supervisor', $supervisor->role);
        $this->assertTrue($supervisor->is_active);
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
                'name' => 'Nope',
                'email' => "nope-{$role}@example.com",
                'password' => 'password123',
            ])->assertStatus(422)->assertJsonValidationErrors('role');
        }

        $this->assertSame(0, User::whereIn('role', ['admin'])->where('email', 'like', 'nope-%')->count());
    }

    public function test_student_program_must_be_in_scope(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $outOfScope = $this->programFor('BSBA-FM', 'CABM-B');

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/coordinator/accounts', [
            'role' => 'student',
            'name' => 'Out Scope',
            'email' => 'outscope@example.com',
            'password' => 'password123',
            'program_id' => $outOfScope->id,
        ])->assertStatus(422);

        $this->assertNull(User::where('email', 'outscope@example.com')->first());
    }
}
