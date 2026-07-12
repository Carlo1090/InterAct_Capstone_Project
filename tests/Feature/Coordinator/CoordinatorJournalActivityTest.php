<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\JournalTemplate;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoordinatorJournalActivityTest extends TestCase
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
            'name' => 'Batch '.uniqid(),
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

    private function enroll(Batch $batch, ?Company $company = null): BatchStudent
    {
        $student = User::factory()->create(['role' => 'student']);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company ??= Company::create(['name' => 'Co '.uniqid(), 'address' => 'Addr', 'is_active' => true]);

        return BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);
    }

    private function entry(BatchStudent $enrollment, string $status, string $date): JournalEntry
    {
        return JournalEntry::create([
            'student_id' => $enrollment->student_id,
            'batch_id' => $enrollment->batch_id,
            'entry_date' => $date,
            'content' => ['task_performed' => 'x'],
            'status' => $status,
            'submitted_at' => $status === 'submitted' ? now() : null,
        ]);
    }

    public function test_default_view_is_today(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $submitted = $this->enroll($batch);
        $missing = $this->enroll($batch);
        $this->entry($submitted, 'submitted', now()->toDateString());
        // $missing has no entry today.

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/journal-activities');

        $response->assertOk();
        $response->assertJsonPath('is_single_day', true);
        $response->assertJsonPath('from', now()->toDateString());

        $rows = collect($response->json('rows'))->keyBy('student_id');
        $this->assertSame('submitted', $rows[$submitted->student_id]['day_status']);
        $this->assertSame('missing', $rows[$missing->student_id]['day_status']);
    }

    public function test_range_returns_submitted_and_missing_tallies(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        $student = $this->enroll($batch);

        $this->entry($student, 'submitted', '2026-06-01');
        $this->entry($student, 'submitted', '2026-06-02');
        $this->entry($student, 'missing', '2026-06-03');
        // Outside the range — must not count.
        $this->entry($student, 'submitted', '2026-07-01');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/journal-activities?from=2026-06-01&to=2026-06-30');

        $response->assertOk();
        $response->assertJsonPath('is_single_day', false);

        $row = collect($response->json('rows'))->firstWhere('student_id', $student->student_id);
        $this->assertSame(2, $row['submitted_count']);
        $this->assertSame(1, $row['missing_count']);
    }

    public function test_company_filter_narrows_rows(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $techph = Company::create(['name' => 'TechPH', 'address' => 'A', 'is_active' => true]);
        $prince = Company::create(['name' => 'Prince', 'address' => 'B', 'is_active' => true]);
        $a = $this->enroll($batch, $techph);
        $this->enroll($batch, $prince);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson("/api/coordinator/journal-activities?company_id={$techph->id}");

        $response->assertOk();
        $rows = collect($response->json('rows'));
        $this->assertCount(1, $rows);
        $this->assertSame($a->student_id, $rows->first()['student_id']);
    }

    public function test_out_of_scope_students_excluded(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $this->batchFor($bsit, $coordinator);

        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoord = $this->coordinatorFor($bsba);
        $otherBatch = $this->batchFor($bsba, $otherCoord);
        $this->enroll($otherBatch);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/journal-activities');

        $response->assertOk();
        $this->assertCount(0, $response->json('rows'));
    }

    public function test_program_filter_narrows_rows_and_rejects_out_of_scope_program(): void
    {
        // A coordinator's department can have multiple programs (e.g. CABM-B
        // has BSA + BSBA-FM), so two in-scope programs don't require attaching
        // the coordinator to a second department (departments are one-per-
        // coordinator as of the 2026-07-12 admin migration).
        $bsa = $this->programFor('BSA', 'CABM-B');
        $bsbaFm = $this->programFor('BSBA-FM', 'CABM-B');
        $coordinator = $this->coordinatorFor($bsa);

        $bsaBatch = $this->batchFor($bsa, $coordinator);
        $inScope = $this->enroll($bsaBatch);

        $bsbaFmBatch = $this->batchFor($bsbaFm, $coordinator);
        $this->enroll($bsbaFmBatch);

        $outsideProgram = $this->programFor('BSIT', 'CAST');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson("/api/coordinator/journal-activities?program_id={$bsa->id}");
        $response->assertOk();
        $rows = collect($response->json('rows'));
        $this->assertCount(1, $rows);
        $this->assertSame($inScope->student_id, $rows->first()['student_id']);

        $forbidden = $this->getJson("/api/coordinator/journal-activities?program_id={$outsideProgram->id}");
        $forbidden->assertForbidden();
    }

    public function test_status_filter_narrows_rows_for_single_day_and_range(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $submitted = $this->enroll($batch);
        $missing = $this->enroll($batch);
        $this->entry($submitted, 'submitted', now()->toDateString());

        Sanctum::actingAs($coordinator, ['*']);

        $submittedOnly = $this->getJson('/api/coordinator/journal-activities?status=submitted');
        $submittedOnly->assertOk();
        $submittedIds = collect($submittedOnly->json('rows'))->pluck('student_id');
        $this->assertTrue($submittedIds->contains($submitted->student_id));
        $this->assertFalse($submittedIds->contains($missing->student_id));

        $missingOnly = $this->getJson('/api/coordinator/journal-activities?status=missing');
        $missingOnly->assertOk();
        $missingIds = collect($missingOnly->json('rows'))->pluck('student_id');
        $this->assertFalse($missingIds->contains($submitted->student_id));
        $this->assertTrue($missingIds->contains($missing->student_id));
    }

    public function test_show_returns_full_entry_content_for_in_scope_student(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        $template = JournalTemplate::create([
            'name' => 'Daily Journal',
            'sections' => [
                ['key' => 'task_performed', 'label' => 'Task Performed', 'prompt' => '', 'required' => true, 'sipp' => false],
                ['key' => 'learnings', 'label' => 'Learnings', 'prompt' => '', 'required' => true, 'sipp' => false],
            ],
            'char_limit' => 1500,
            'is_active' => true,
        ]);
        $template->programs()->sync([$bsit->id]);

        $batch = $this->batchFor($bsit, $coordinator);
        $batch->update(['journal_template_id' => $template->id]);

        $enrollment = $this->enroll($batch);
        $date = now()->toDateString();
        JournalEntry::create([
            'student_id' => $enrollment->student_id,
            'batch_id' => $batch->id,
            'entry_date' => $date,
            'content' => ['task_performed' => 'Wrote unit tests', 'learnings' => 'Laravel scoping'],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson("/api/coordinator/journal-activities/{$enrollment->student_id}/{$date}");

        $response->assertOk();
        $response->assertJsonPath('status', 'submitted');
        $sections = collect($response->json('sections'))->keyBy('key');
        $this->assertSame('Task Performed', $sections['task_performed']['label']);
        $this->assertSame('Wrote unit tests', $sections['task_performed']['text']);
        $this->assertSame('Laravel scoping', $sections['learnings']['text']);
    }

    public function test_show_is_forbidden_for_out_of_scope_student(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $this->batchFor($bsit, $coordinator);

        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoord = $this->coordinatorFor($bsba);
        $otherBatch = $this->batchFor($bsba, $otherCoord);
        $outsideEnrollment = $this->enroll($otherBatch);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson("/api/coordinator/journal-activities/{$outsideEnrollment->student_id}/".now()->toDateString());

        $response->assertForbidden();
    }
}
