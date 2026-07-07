<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\JournalTemplate;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JournalTemplateTest extends TestCase
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

    private function coordinatorWithBatch(Program $program): User
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);

        Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Batch '.uniqid(),
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        return $coordinator;
    }

    private function validSections(): array
    {
        return [
            ['key' => 'task_performed', 'label' => 'Task Performed', 'prompt' => 'Describe tasks.', 'required' => true, 'sipp' => false],
            ['key' => 'skills_applied', 'label' => 'Skills Applied', 'prompt' => 'Skills used?', 'required' => false, 'sipp' => false],
        ];
    }

    public function test_coordinator_lists_only_own_program_templates(): void
    {
        $programA = $this->programFor('BSIT');
        $programB = $this->programFor('BSBA-FM');

        $coordinator = $this->coordinatorWithBatch($programA);

        JournalTemplate::create([
            'program_id' => $programA->id,
            'name' => 'Own Template',
            'sections' => $this->validSections(),
            'word_limit' => 500,
            'is_active' => true,
        ]);

        JournalTemplate::create([
            'program_id' => $programB->id,
            'name' => 'Other Template',
            'sections' => $this->validSections(),
            'word_limit' => 500,
            'is_active' => true,
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/journal-templates');

        $response->assertOk();
        $names = collect($response->json('templates'))->pluck('name');
        $this->assertTrue($names->contains('Own Template'));
        $this->assertFalse($names->contains('Other Template'));

        $programIds = collect($response->json('programs'))->pluck('id');
        $this->assertTrue($programIds->contains($programA->id));
        $this->assertFalse($programIds->contains($programB->id));
    }

    public function test_store_rejects_zero_required_sections(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorWithBatch($program);
        Sanctum::actingAs($coordinator, ['*']);

        $sections = $this->validSections();
        $sections[0]['required'] = false;

        $response = $this->postJson('/api/coordinator/journal-templates', [
            'program_id' => $program->id,
            'name' => 'No Required Template',
            'word_limit' => 500,
            'sections' => $sections,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sections']);
    }

    public function test_store_rejects_duplicate_or_invalid_keys(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorWithBatch($program);
        Sanctum::actingAs($coordinator, ['*']);

        $duplicateKeySections = $this->validSections();
        $duplicateKeySections[1]['key'] = 'task_performed';

        $response = $this->postJson('/api/coordinator/journal-templates', [
            'program_id' => $program->id,
            'name' => 'Duplicate Keys Template',
            'word_limit' => 500,
            'sections' => $duplicateKeySections,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sections']);

        $invalidKeySections = $this->validSections();
        $invalidKeySections[1]['key'] = 'Invalid Key!';

        $response2 = $this->postJson('/api/coordinator/journal-templates', [
            'program_id' => $program->id,
            'name' => 'Invalid Key Template',
            'word_limit' => 500,
            'sections' => $invalidKeySections,
        ]);

        $response2->assertStatus(422);
        $response2->assertJsonValidationErrors(['sections.1.key']);
    }

    public function test_coordinator_gets_403_editing_another_programs_template(): void
    {
        $programA = $this->programFor('BSIT');
        $programB = $this->programFor('BSBA-FM');

        $this->coordinatorWithBatch($programA);
        $otherCoordinator = $this->coordinatorWithBatch($programB);

        $template = JournalTemplate::create([
            'program_id' => $programA->id,
            'name' => 'Template A',
            'sections' => $this->validSections(),
            'word_limit' => 500,
            'is_active' => true,
        ]);

        Sanctum::actingAs($otherCoordinator, ['*']);

        $response = $this->putJson("/api/coordinator/journal-templates/{$template->id}", [
            'program_id' => $programA->id,
            'name' => 'Hacked Template',
            'word_limit' => 500,
            'sections' => $this->validSections(),
        ]);

        $response->assertStatus(403);
    }

    public function test_update_returns_affected_entries_when_removing_a_used_key(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorWithBatch($program);

        $template = JournalTemplate::create([
            'program_id' => $program->id,
            'name' => 'Template With Data',
            'sections' => $this->validSections(),
            'word_limit' => 500,
            'is_active' => true,
        ]);

        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Data Batch',
            'journal_template_id' => $template->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'entry_date' => now()->toDateString(),
            'content' => ['task_performed' => 'Did work.', 'skills_applied' => 'Used Laravel.'],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $newSections = [$this->validSections()[0]];

        $response = $this->putJson("/api/coordinator/journal-templates/{$template->id}", [
            'program_id' => $program->id,
            'name' => 'Template With Data',
            'word_limit' => 500,
            'sections' => $newSections,
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('affected_entries'));
        $this->assertDatabaseHas('journal_entries', ['student_id' => $student->id]);
    }
}
