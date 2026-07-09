<?php

namespace Tests\Unit\Models;

use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_coordinator_program_ids_resolves_all_programs_in_assigned_department(): void
    {
        $department = Department::create(['code' => 'CAST', 'name' => 'CAST', 'is_active' => true]);
        $programA = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BSIT', 'is_active' => true]);
        $programB = Program::create(['department_id' => $department->id, 'code' => 'BSCS', 'name' => 'BSCS', 'is_active' => true]);

        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($department->id);

        $programIds = $coordinator->coordinatorProgramIds();

        $this->assertTrue($programIds->contains($programA->id));
        $this->assertTrue($programIds->contains($programB->id));
        $this->assertCount(2, $programIds);
    }

    public function test_coordinator_program_ids_is_empty_with_no_department_and_no_batches(): void
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);

        $this->assertTrue($coordinator->coordinatorProgramIds()->isEmpty());
    }
}
