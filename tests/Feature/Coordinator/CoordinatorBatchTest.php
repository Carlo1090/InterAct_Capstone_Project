<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\Department;
use App\Models\JournalTemplate;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoordinatorBatchTest extends TestCase
{
    use RefreshDatabase;

    private function programFor(string $code): Program
    {
        $department = Department::firstOrCreate(
            ['code' => 'CAST'],
            ['name' => 'College of Arts, Sciences and Technology', 'is_active' => true]
        );

        return Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => $code],
            ['name' => $code, 'is_active' => true]
        );
    }

    private function validPayload(Program $program, array $overrides = []): array
    {
        return [
            'program_id' => $program->id,
            'name' => 'Batch '.uniqid(),
            'academic_year' => '2026',
            'semester' => 'Internship',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addMonths(4)->toDateString(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00',
            ...$overrides,
        ];
    }

    public function test_coordinator_creates_a_batch_for_their_own_program(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);
        $coordinator->departmentsCoordinated()->attach($program->department_id);
        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/batches', $this->validPayload($program));

        $response->assertCreated();
        $this->assertDatabaseHas('batches', [
            'name' => $response->json('name'),
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
        ]);
    }

    public function test_coordinator_cannot_create_a_batch_for_a_program_outside_their_scope(): void
    {
        $ownProgram = $this->programFor('BSIT');
        $otherProgram = $this->programFor('BSBA-FM');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $ownProgram->id]);
        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/batches', $this->validPayload($otherProgram));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['program_id']);
    }

    public function test_coordinator_cannot_use_a_journal_template_from_another_program(): void
    {
        $program = $this->programFor('BSIT');
        $otherProgram = $this->programFor('BSBA-FM');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);

        $foreignTemplate = JournalTemplate::create([
            'program_id' => $otherProgram->id,
            'name' => 'Foreign Template',
            'sections' => [
                ['key' => 'task_performed', 'label' => 'Task Performed', 'prompt' => '', 'required' => true, 'sipp' => false],
            ],
            'word_limit' => 500,
            'is_active' => true,
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/batches', $this->validPayload($program, [
            'journal_template_id' => $foreignTemplate->id,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['journal_template_id']);
    }

    public function test_coordinator_can_update_their_own_batch(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);

        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Original Name',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026',
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->putJson("/api/coordinator/batches/{$batch->id}", ['name' => 'Updated Name']);

        $response->assertOk()->assertJsonPath('name', 'Updated Name');
    }

    public function test_coordinator_gets_403_updating_another_coordinators_batch(): void
    {
        $program = $this->programFor('BSIT');
        $owner = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);
        $other = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);

        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $owner->id,
            'name' => 'Owner Batch',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026',
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        Sanctum::actingAs($other, ['*']);

        $response = $this->putJson("/api/coordinator/batches/{$batch->id}", ['name' => 'Hacked Name']);

        $response->assertStatus(403);
    }
}
