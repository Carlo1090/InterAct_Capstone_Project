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

    /**
     * Create a template covering the given program(s) via the pivot.
     *
     * @param  Program|array<int, Program>  $programs
     */
    private function makeTemplate(Program|array $programs, string $name, ?array $sections = null): JournalTemplate
    {
        $template = JournalTemplate::create([
            'name' => $name,
            'sections' => $sections ?? $this->validSections(),
            'char_limit' => 1500,
            'is_active' => true,
        ]);

        $ids = collect(is_array($programs) ? $programs : [$programs])->pluck('id')->all();
        $template->programs()->sync($ids);

        return $template;
    }

    private function validSections(): array
    {
        return [
            ['key' => 'task_performed', 'label' => 'Task Performed', 'prompt' => 'Describe tasks.', 'required' => true, 'sipp' => false],
            ['key' => 'skills_applied', 'label' => 'Skills Applied', 'prompt' => 'Skills used?', 'required' => false, 'sipp' => false],
        ];
    }

    public function test_coordinator_with_assigned_department_but_no_batches_can_list_templates(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);
        $coordinator->departmentsCoordinated()->attach($program->department_id);

        $this->makeTemplate($program, 'Bootstrap Template');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/journal-templates');

        $response->assertOk();
        $programIds = collect($response->json('programs'))->pluck('id');
        $this->assertTrue($programIds->contains($program->id));
        $names = collect($response->json('templates'))->pluck('name');
        $this->assertTrue($names->contains('Bootstrap Template'));
    }

    public function test_coordinator_with_assigned_department_but_no_batches_can_create_template(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator', 'program_id' => $program->id]);
        $coordinator->departmentsCoordinated()->attach($program->department_id);
        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/journal-templates', [
            'program_ids' => [$program->id],
            'name' => 'Bootstrap Template',
            'char_limit' => 1500,
            'sections' => $this->validSections(),
        ]);

        $response->assertCreated();
        $template = JournalTemplate::where('name', 'Bootstrap Template')->firstOrFail();
        $this->assertDatabaseHas('journal_template_program', [
            'journal_template_id' => $template->id,
            'program_id' => $program->id,
        ]);
    }

    public function test_coordinator_lists_only_own_program_templates(): void
    {
        $programA = $this->programFor('BSIT');
        $programB = $this->programFor('BSBA-FM');

        $coordinator = $this->coordinatorWithBatch($programA);

        $this->makeTemplate($programA, 'Own Template');
        $this->makeTemplate($programB, 'Other Template');

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
            'program_ids' => [$program->id],
            'name' => 'No Required Template',
            'char_limit' => 1500,
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
            'program_ids' => [$program->id],
            'name' => 'Duplicate Keys Template',
            'char_limit' => 1500,
            'sections' => $duplicateKeySections,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sections']);

        $invalidKeySections = $this->validSections();
        $invalidKeySections[1]['key'] = 'Invalid Key!';

        $response2 = $this->postJson('/api/coordinator/journal-templates', [
            'program_ids' => [$program->id],
            'name' => 'Invalid Key Template',
            'char_limit' => 1500,
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

        $template = $this->makeTemplate($programA, 'Template A');

        Sanctum::actingAs($otherCoordinator, ['*']);

        $response = $this->putJson("/api/coordinator/journal-templates/{$template->id}", [
            'program_ids' => [$programA->id],
            'name' => 'Hacked Template',
            'char_limit' => 1500,
            'sections' => $this->validSections(),
        ]);

        $response->assertStatus(403);
    }

    public function test_update_returns_affected_entries_when_removing_a_used_key(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorWithBatch($program);

        $template = $this->makeTemplate($program, 'Template With Data');

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
            'program_ids' => [$program->id],
            'name' => 'Template With Data',
            'char_limit' => 1500,
            'sections' => $newSections,
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('affected_entries'));
        $this->assertDatabaseHas('journal_entries', ['student_id' => $student->id]);
    }

    public function test_template_can_span_two_in_scope_programs(): void
    {
        $bsit = $this->programFor('BSIT');
        $fm = $this->programFor('BSBA-FM'); // same CAST department -> both in scope
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($bsit->department_id);
        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/journal-templates', [
            'program_ids' => [$bsit->id, $fm->id],
            'name' => 'Shared Template',
            'char_limit' => 1500,
            'sections' => $this->validSections(),
        ]);

        $response->assertCreated();
        $template = JournalTemplate::where('name', 'Shared Template')->firstOrFail();
        $this->assertDatabaseHas('journal_template_program', ['journal_template_id' => $template->id, 'program_id' => $bsit->id]);
        $this->assertDatabaseHas('journal_template_program', ['journal_template_id' => $template->id, 'program_id' => $fm->id]);
    }

    public function test_second_template_claiming_a_used_program_is_rejected(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($bsit->department_id);

        $this->makeTemplate($bsit, 'First Template');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/journal-templates', [
            'program_ids' => [$bsit->id],
            'name' => 'Second Template',
            'char_limit' => 1500,
            'sections' => $this->validSections(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['program_ids']);
    }

    public function test_out_of_scope_program_is_rejected(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($bsit->department_id);

        $otherDepartment = Department::firstOrCreate(['code' => 'CABM-H'], ['name' => 'CABM-H', 'is_active' => true]);
        $outProgram = Program::firstOrCreate(
            ['department_id' => $otherDepartment->id, 'code' => 'BSTM'],
            ['name' => 'BSTM', 'is_active' => true]
        );

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->postJson('/api/coordinator/journal-templates', [
            'program_ids' => [$outProgram->id],
            'name' => 'Out Of Scope Template',
            'char_limit' => 1500,
            'sections' => $this->validSections(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['program_ids.0']);
    }

    public function test_sipp_flag_on_non_trio_key_is_rejected(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($bsit->department_id);
        Sanctum::actingAs($coordinator, ['*']);

        $sections = [
            ['key' => 'task_performed', 'label' => 'Task Performed', 'prompt' => 'Tasks.', 'required' => true, 'sipp' => false],
            ['key' => 'random_notes', 'label' => 'Random Notes', 'prompt' => 'Notes.', 'required' => false, 'sipp' => true],
        ];

        $response = $this->postJson('/api/coordinator/journal-templates', [
            'program_ids' => [$bsit->id],
            'name' => 'Rogue SIPP Template',
            'char_limit' => 1500,
            'sections' => $sections,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sections.1.sipp']);
    }

    public function test_sipp_trio_keys_are_accepted(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($bsit->department_id);
        Sanctum::actingAs($coordinator, ['*']);

        $sections = [
            ['key' => 'task_performed', 'label' => 'Task Performed', 'prompt' => 'Tasks.', 'required' => true, 'sipp' => false],
            ['key' => 'issues_concerns', 'label' => 'Issues', 'prompt' => 'Issues.', 'required' => false, 'sipp' => true],
            ['key' => 'solutions', 'label' => 'Solutions', 'prompt' => 'Solutions.', 'required' => false, 'sipp' => true],
            ['key' => 'recommendations', 'label' => 'Recommendations', 'prompt' => 'Recs.', 'required' => false, 'sipp' => true],
        ];

        $response = $this->postJson('/api/coordinator/journal-templates', [
            'program_ids' => [$bsit->id],
            'name' => 'Trio Template',
            'char_limit' => 1500,
            'sections' => $sections,
        ]);

        $response->assertCreated();
    }

    public function test_index_returns_assigned_template_id_per_program(): void
    {
        $bsit = $this->programFor('BSIT');
        $fm = $this->programFor('BSBA-FM'); // in scope, unclaimed
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($bsit->department_id);

        $template = $this->makeTemplate($bsit, 'Claiming Template');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/journal-templates');
        $response->assertOk();

        $programs = collect($response->json('programs'));
        $bsitRow = $programs->firstWhere('id', $bsit->id);
        $fmRow = $programs->firstWhere('id', $fm->id);

        $this->assertSame($template->id, $bsitRow['assigned_template_id']);
        $this->assertNull($fmRow['assigned_template_id']);
    }
}
