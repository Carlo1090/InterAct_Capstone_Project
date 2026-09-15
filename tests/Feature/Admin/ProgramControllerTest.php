<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\DepartmentProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgramControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function department(string $code = 'CAST', string $name = 'College of Arts, Sciences and Technology'): Department
    {
        return Department::create(['code' => $code, 'name' => $name, 'is_active' => true]);
    }

    public function test_index_returns_programs_with_department(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();
        Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $response = $this->getJson('/api/admin/programs');

        $response->assertOk();
        $this->assertSame('CAST', collect($response->json())->firstWhere('code', 'BSIT')['department']['code']);
    }

    public function test_show_returns_the_program_with_its_department(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $response = $this->getJson("/api/admin/programs/{$program->id}");

        $response->assertOk();
        $this->assertSame('CAST', $response->json('department.code'));
    }

    public function test_an_admin_creates_a_program_under_a_department(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();

        $response = $this->postJson('/api/admin/programs', [
            'department_id' => $department->id,
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
        ]);

        $response->assertCreated();
        $this->assertSame('CAST', $response->json('department.code'));
        $this->assertDatabaseHas('programs', [
            'department_id' => $department->id,
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'is_active' => true,
        ]);
    }

    /**
     * The whole point of "programs could be many": a department takes as many as
     * the registrar has, with no ceiling anywhere in the stack.
     */
    public function test_a_department_takes_many_programs(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();

        foreach (range(1, 12) as $n) {
            $this->postJson('/api/admin/programs', [
                'department_id' => $department->id,
                'code' => "PROG-{$n}",
                'name' => "Program Number {$n}",
            ])->assertCreated();
        }

        $this->assertSame(12, Program::where('department_id', $department->id)->count());
        $this->assertCount(12, $this->getJson('/api/admin/programs')->json());
    }

    /**
     * `programs` is UNIQUE(department_id, code), NOT unique on code alone —
     * departments are independent top-level units, so two of them may legitimately
     * run the same program code. Validating globally would refuse a legal program.
     */
    public function test_the_same_code_is_allowed_in_a_different_department(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $cast = $this->department();
        $cabmb = $this->department('CABM-B', 'Business Department');

        $this->postJson('/api/admin/programs', ['department_id' => $cast->id, 'code' => 'BSBA', 'name' => 'BS Business Administration'])
            ->assertCreated();
        $this->postJson('/api/admin/programs', ['department_id' => $cabmb->id, 'code' => 'BSBA', 'name' => 'BS Business Administration'])
            ->assertCreated();

        $this->assertSame(2, Program::where('code', 'BSBA')->count());
    }

    public function test_a_duplicate_code_within_one_department_is_refused(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();
        Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $this->postJson('/api/admin/programs', ['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'Duplicate'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_an_admin_renames_a_program_and_can_deactivate_it(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BSIT', 'is_active' => true]);

        $this->putJson("/api/admin/programs/{$program->id}", [
            'code' => 'BSIT',
            'name' => 'BS Information Technology',
            'is_active' => false,
        ])->assertOk();

        $program->refresh();
        $this->assertSame('BS Information Technology', $program->name);
        $this->assertFalse($program->is_active);
    }

    /**
     * A mistyped code must stay fixable — the alternative is deactivate-and-recreate,
     * which strands every batch already pointing at the original row.
     */
    public function test_a_program_code_can_be_corrected(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSTI', 'name' => 'BS Information Technology', 'is_active' => true]);

        $this->putJson("/api/admin/programs/{$program->id}", ['code' => 'BSIT', 'name' => 'BS Information Technology'])
            ->assertOk();

        $this->assertSame('BSIT', $program->refresh()->code);
    }

    public function test_an_update_cannot_re_parent_a_program_to_another_department(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $cast = $this->department();
        $cabmb = $this->department('CABM-B', 'Business Department');
        $program = Program::create(['department_id' => $cast->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $this->putJson("/api/admin/programs/{$program->id}", [
            'code' => 'BSIT',
            'name' => 'BS Information Technology',
            'department_id' => $cabmb->id,
        ])->assertOk();

        $this->assertSame($cast->id, $program->refresh()->department_id);
    }

    /**
     * THE ONE THAT WOULD HAVE BITTEN. `coordinatorProgramIds()` caches every
     * program in the coordinator's department for a DAY, so without invalidation a
     * newly-added program is invisible to the very coordinator who has to build a
     * batch for it — with the database perfectly correct the whole time.
     */
    public function test_a_new_program_is_immediately_in_its_coordinators_scope(): void
    {
        $department = $this->department();
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $department->coordinators()->attach($coordinator->id);

        // Warm the cache the way any coordinator page load would.
        $this->assertCount(0, $coordinator->coordinatorProgramIds());

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson('/api/admin/programs', [
            'department_id' => $department->id,
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
        ])->assertCreated();

        $ids = User::find($coordinator->id)->coordinatorProgramIds();
        $this->assertCount(1, $ids);
        $this->assertSame(Program::where('code', 'BSCS')->value('id'), $ids->first());
    }

    public function test_the_cached_program_list_reflects_a_create_immediately(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = $this->department();
        $this->getJson('/api/admin/programs')->assertOk();

        $this->postJson('/api/admin/programs', ['department_id' => $department->id, 'code' => 'BSCS', 'name' => 'BS Computer Science'])
            ->assertCreated();

        $this->assertCount(1, $this->getJson('/api/admin/programs')->json());
        $this->assertSame(1, collect($this->getJson('/api/admin/departments')->json())->firstWhere('code', 'CAST')['programs_count']);
    }

    /**
     * DepartmentProgramSeeder used to PRUNE — deleting every program outside its
     * own hardcoded list. That was safe only while nothing could create an eighth
     * program. It is not safe now: `batches.program_id` is cascadeOnDelete and
     * `batch_students` cascades from `batches`, so a re-seed to correct reference
     * data would have taken an admin-created program, its batches, its enrollments
     * and every journal under them, silently.
     */
    public function test_an_admin_created_program_survives_a_reference_re_seed(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $this->seed(DepartmentProgramSeeder::class);

        $cast = Department::where('code', 'CAST')->firstOrFail();
        $this->postJson('/api/admin/programs', [
            'department_id' => $cast->id,
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
        ])->assertCreated();

        $this->seed(DepartmentProgramSeeder::class);

        $this->assertDatabaseHas('programs', ['department_id' => $cast->id, 'code' => 'BSCS']);
        // The canonical seven are still exactly as seeded, so the seeder has not
        // stopped doing its own job.
        $this->assertSame(8, Program::count());
        $this->assertDatabaseHas('programs', ['department_id' => $cast->id, 'code' => 'BSIT']);
    }

    public function test_non_admin_cannot_access_program_routes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'coordinator']), ['*']);

        $department = $this->department();
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology', 'is_active' => true]);

        $this->getJson('/api/admin/programs')->assertStatus(403);
        $this->getJson("/api/admin/programs/{$program->id}")->assertStatus(403);
        $this->postJson('/api/admin/programs', ['department_id' => $department->id, 'code' => 'X', 'name' => 'X'])->assertStatus(403);
        $this->putJson("/api/admin/programs/{$program->id}", ['code' => 'X', 'name' => 'X'])->assertStatus(403);
    }
}
