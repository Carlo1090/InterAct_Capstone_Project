<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\SippAnnualReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnualSippReportTest extends TestCase
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

    private function batchFor(Program $program, User $coordinator, string $academicYear): Batch
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
            'academic_year' => $academicYear,
            'semester' => 'Internship',
            'is_active' => true,
        ]);
    }

    private function sippEntry(User $student, Batch $batch, string $date, array $sipp): JournalEntry
    {
        return JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'entry_date' => $date,
            'content' => array_merge(['task_performed' => 'Did work.'], $sipp),
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    }

    public function test_index_returns_only_in_scope_programs_and_years(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $this->batchFor($bsit, $coordinator, '2026');

        // Out-of-scope program in a different department, with its own year.
        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoordinator = $this->coordinatorFor($bsba);
        $this->batchFor($bsba, $otherCoordinator, '2025');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/annual-sipp');

        $response->assertOk();
        $codes = collect($response->json('programs'))->pluck('code');
        $this->assertTrue($codes->contains('BSIT'));
        $this->assertFalse($codes->contains('BSBA-FM'));

        $years = collect($response->json('academic_years'));
        $this->assertTrue($years->contains('2026'));
        $this->assertFalse($years->contains('2025'));
    }

    public function test_show_out_of_scope_program_returns_403(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        $bsba = $this->programFor('BSBA-FM', 'CABM-B');

        Sanctum::actingAs($coordinator, ['*']);

        $this->getJson("/api/coordinator/annual-sipp/{$bsba->id}?academic_year=2026")
            ->assertStatus(403);
    }

    public function test_show_returns_candidate_rows_from_sipp_entries(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026');
        $student = User::factory()->create(['role' => 'student', 'name' => 'Ana Cruz', 'program_id' => $bsit->id]);

        $this->sippEntry($student, $batch, now()->subDays(4)->toDateString(), ['issues_concerns' => 'Network was down.']);
        $this->sippEntry($student, $batch, now()->subDays(3)->toDateString(), ['solutions' => 'Escalated to IT.']);
        // A non-SIPP entry should NOT become a candidate row.
        $this->sippEntry($student, $batch, now()->subDays(2)->toDateString(), []);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson("/api/coordinator/annual-sipp/{$bsit->id}?academic_year=2026");

        $response->assertOk();
        $rows = collect($response->json('rows'));
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($row) => $row['included'] === true));
        $this->assertSame('Ana Cruz', $rows->first()['student_name']);
    }

    public function test_show_excludes_non_submitted_entries(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026');
        $student = User::factory()->create(['role' => 'student', 'name' => 'Ana Cruz', 'program_id' => $bsit->id]);

        // A DRAFT entry with SIPP content must NOT leak into the official report.
        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'entry_date' => now()->subDays(4)->toDateString(),
            'content' => ['task_performed' => 'Did work.', 'issues_concerns' => 'Draft leak.'],
            'status' => 'draft',
        ]);

        // A SUBMITTED entry with SIPP content must appear.
        $this->sippEntry($student, $batch, now()->subDays(3)->toDateString(), ['issues_concerns' => 'Real submitted issue.']);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson("/api/coordinator/annual-sipp/{$bsit->id}?academic_year=2026");

        $response->assertOk();
        $rows = collect($response->json('rows'));
        $this->assertCount(1, $rows);
        $this->assertSame('Real submitted issue.', $rows->first()['issues_concerns']);
        $this->assertFalse(
            $rows->contains(fn ($row) => $row['issues_concerns'] === 'Draft leak.'),
            'Draft entry should not appear in the Annual SIPP Report rows.'
        );
    }

    public function test_show_requires_academic_year(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        Sanctum::actingAs($coordinator, ['*']);

        $this->getJson("/api/coordinator/annual-sipp/{$bsit->id}")->assertStatus(422);
    }

    public function test_save_then_show_returns_curated_state(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026');
        $student = User::factory()->create(['role' => 'student', 'program_id' => $bsit->id]);

        $rowA = $this->sippEntry($student, $batch, now()->subDays(5)->toDateString(), ['issues_concerns' => 'Original A.']);
        $rowB = $this->sippEntry($student, $batch, now()->subDays(4)->toDateString(), ['issues_concerns' => 'Original B.']);
        $rowC = $this->sippEntry($student, $batch, now()->subDays(3)->toDateString(), ['issues_concerns' => 'Original C.']);

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson("/api/coordinator/annual-sipp/{$bsit->id}", [
            'academic_year' => '2026',
            'heading' => 'BS Information Technology',
            'status' => 'finalized',
            'signatory_prepared_name' => 'Coordinator Name',
            'signatory_prepared_title' => 'Practicum Coordinator, CAST',
            'rows' => [
                ['id' => $rowA->id, 'issues_concerns' => 'EDITED A.', 'solutions' => '', 'recommendations' => '', 'included' => true],
                ['id' => $rowB->id, 'issues_concerns' => 'Original B.', 'solutions' => '', 'recommendations' => '', 'included' => false],
            ],
            'deleted_ids' => [$rowC->id],
        ])->assertOk();

        $this->assertDatabaseHas('sipp_annual_reports', [
            'coordinator_id' => $coordinator->id,
            'program_id' => $bsit->id,
            'academic_year' => '2026',
            'status' => 'finalized',
        ]);

        $response = $this->getJson("/api/coordinator/annual-sipp/{$bsit->id}?academic_year=2026");
        $response->assertOk();

        $rows = collect($response->json('rows'))->keyBy('id');
        $this->assertSame('EDITED A.', $rows[$rowA->id]['issues_concerns']);
        $this->assertFalse($rows[$rowB->id]['included']);
        $this->assertArrayNotHasKey($rowC->id, $rows->all(), 'Deleted row should not reappear.');

        $this->assertSame('finalized', $response->json('status'));
        $this->assertSame('BS Information Technology', $response->json('meta.heading'));
        $this->assertSame('Coordinator Name', $response->json('meta.signatory_prepared_name'));
        // Untouched signatory falls back to the document default.
        $this->assertSame('MA. ANGELICA B. CALUNSAG, MSA, CPA', $response->json('meta.signatory_certified_name'));
    }

    public function test_show_defaults_heading_to_program_name_and_document_signatories(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $this->batchFor($bsit, $coordinator, '2026');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson("/api/coordinator/annual-sipp/{$bsit->id}?academic_year=2026");

        $response->assertOk();
        $this->assertSame($bsit->name, $response->json('meta.heading'));
        $this->assertSame('MARIA ANTONNETTE B. GULILAT, MA, LPT', $response->json('meta.signatory_prepared_name'));
    }

    public function test_pdf_downloads_and_reflects_included_rows_only(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026');
        $student = User::factory()->create(['role' => 'student', 'program_id' => $bsit->id]);

        $included = $this->sippEntry($student, $batch, now()->subDays(5)->toDateString(), ['issues_concerns' => 'Included issue.']);
        $excluded = $this->sippEntry($student, $batch, now()->subDays(4)->toDateString(), ['issues_concerns' => 'Excluded issue.']);

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson("/api/coordinator/annual-sipp/{$bsit->id}", [
            'academic_year' => '2026',
            'heading' => 'BS Information Technology',
            'status' => 'draft',
            'rows' => [
                ['id' => $included->id, 'issues_concerns' => 'Included issue.', 'solutions' => '', 'recommendations' => '', 'included' => true],
                ['id' => $excluded->id, 'issues_concerns' => 'Excluded issue.', 'solutions' => '', 'recommendations' => '', 'included' => false],
            ],
        ])->assertOk();

        $response = $this->get("/api/coordinator/annual-sipp/{$bsit->id}/pdf?academic_year=2026");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertGreaterThan(0, strlen($response->getContent()));
    }

    public function test_pdf_out_of_scope_program_returns_403(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $bsba = $this->programFor('BSBA-FM', 'CABM-B');

        Sanctum::actingAs($coordinator, ['*']);

        $this->get("/api/coordinator/annual-sipp/{$bsba->id}/pdf?academic_year=2026")->assertStatus(403);
    }

    public function test_save_out_of_scope_program_returns_403(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $bsba = $this->programFor('BSBA-FM', 'CABM-B');

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson("/api/coordinator/annual-sipp/{$bsba->id}", [
            'academic_year' => '2026',
            'heading' => 'X',
            'status' => 'draft',
            'rows' => [],
        ])->assertStatus(403);

        $this->assertSame(0, SippAnnualReport::count());
    }
}
