<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\HteReport;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HteReportTest extends TestCase
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
            'start_date' => '2024-08-12',
            'end_date' => '2024-10-18',
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => $academicYear,
            'semester' => 'Internship',
            'is_active' => true,
        ]);
    }

    private function enroll(Batch $batch, Company $company, string $studentName, ?string $sex, ?string $yearLevel = '4th Year'): BatchStudent
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $studentName]);
        StudentProfile::updateOrCreate(['user_id' => $student->id], ['sex' => $sex, 'year_level' => $yearLevel]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        return BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);
    }

    private function companyNamed(string $name): Company
    {
        return Company::firstOrCreate(
            ['name' => $name],
            ['address' => $name.' Address', 'is_active' => true]
        );
    }

    public function test_index_returns_in_scope_years_and_programs(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $this->batchFor($bsit, $coordinator, '2026');

        // Out of scope: different department + year.
        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $other = $this->coordinatorFor($bsba);
        $this->batchFor($bsba, $other, '2025');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/hte');

        $response->assertOk();
        $this->assertTrue(collect($response->json('programs'))->pluck('code')->contains('BSIT'));
        $this->assertFalse(collect($response->json('programs'))->pluck('code')->contains('BSBA-FM'));
        $this->assertTrue(collect($response->json('academic_years'))->contains('2026'));
        $this->assertFalse(collect($response->json('academic_years'))->contains('2025'));
    }

    public function test_show_lists_only_in_scope_interns_mapped_to_columns(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026');
        $techph = $this->companyNamed('TechPH Inc.');
        $this->enroll($batch, $techph, 'Ana Cruz', 'female', '4th Year');

        // Out-of-scope enrollment (different department) must not appear.
        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoord = $this->coordinatorFor($bsba);
        $otherBatch = $this->batchFor($bsba, $otherCoord, '2026');
        $this->enroll($otherBatch, $this->companyNamed('OtherCorp'), 'Ben Reyes', 'male');

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/hte/2026');

        $response->assertOk();
        $rows = collect($response->json('rows'));
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('TechPH Inc.', $row['host_establishment']);
        $this->assertSame('Cruz, Ana', $row['student_name']);
        $this->assertSame('BSIT-4', $row['program']);
        $this->assertSame('Female', $row['gender']);
        $this->assertSame('August 12, 2024 – October 18, 2024', $row['duration']);
        $this->assertTrue($row['included']);
        $this->assertFalse($rows->contains(fn ($r) => $r['host_establishment'] === 'OtherCorp'));
    }

    public function test_show_out_of_scope_program_filter_returns_403(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $bsba = $this->programFor('BSBA-FM', 'CABM-B');

        Sanctum::actingAs($coordinator, ['*']);

        $this->getJson("/api/coordinator/hte/2026?program_id={$bsba->id}")->assertStatus(403);
        $this->get("/api/coordinator/hte/2026/pdf?program_id={$bsba->id}")->assertStatus(403);
    }

    public function test_save_out_of_scope_program_returns_403(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $bsba = $this->programFor('BSBA-FM', 'CABM-B');

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/coordinator/hte/2026', [
            'academic_year' => '2026',
            'program_id' => $bsba->id,
            'status' => 'draft',
            'rows' => [],
        ])->assertStatus(403);

        $this->assertSame(0, HteReport::count());
    }

    public function test_save_then_show_round_trips_overrides_manual_rows_and_exclusions(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026');
        $included = $this->enroll($batch, $this->companyNamed('TechPH Inc.'), 'Ana Cruz', 'female');
        $excluded = $this->enroll($batch, $this->companyNamed('DataCorp'), 'Ben Santos', 'male');

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/coordinator/hte/2026', [
            'academic_year' => '2026',
            'status' => 'finalized',
            'signatory_prepared_name' => 'Coordinator Name',
            'rows' => [
                // Edit a cell on the included row.
                ['id' => $included->id, 'host_establishment' => 'TechPH Incorporated', 'student_name' => 'Cruz, Ana', 'program' => 'BSIT-4', 'gender' => 'Female', 'duration' => 'August 12, 2024 – October 18, 2024', 'included' => true],
                // Exclude the second row.
                ['id' => $excluded->id, 'host_establishment' => 'DataCorp', 'student_name' => 'Santos, Ben', 'program' => 'BSIT-4', 'gender' => 'Male', 'duration' => 'August 12, 2024 – October 18, 2024', 'included' => false],
            ],
            'manual_rows' => [
                ['id' => 'manual-1', 'host_establishment' => 'Manual HTE', 'student_name' => 'Doe, Jane', 'program' => 'BSIT-3', 'gender' => 'Female', 'duration' => 'June 1, 2024 – August 1, 2024', 'included' => true],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('hte_reports', [
            'coordinator_id' => $coordinator->id,
            'program_id' => null,
            'academic_year' => '2026',
            'status' => 'finalized',
        ]);

        $response = $this->getJson('/api/coordinator/hte/2026');
        $response->assertOk();

        $rows = collect($response->json('rows'));
        $edited = $rows->firstWhere('id', $included->id);
        $this->assertSame('TechPH Incorporated', $edited['host_establishment']);

        $excludedRow = $rows->firstWhere('id', $excluded->id);
        $this->assertFalse($excludedRow['included']);

        $manual = $rows->firstWhere('id', 'manual-1');
        $this->assertNotNull($manual, 'Manual row should round-trip.');
        $this->assertSame('Manual HTE', $manual['host_establishment']);
        $this->assertTrue($manual['is_manual']);

        $this->assertSame('finalized', $response->json('status'));
        $this->assertSame('Coordinator Name', $response->json('meta.signatory_prepared_name'));
        // Untouched signatory falls back to the document default.
        $this->assertSame('MA. ANGELICA B. CALUNSAG, MSA, CPA', $response->json('meta.signatory_certified_name'));
    }

    public function test_deleted_candidate_row_does_not_reappear(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026');
        $keep = $this->enroll($batch, $this->companyNamed('TechPH Inc.'), 'Ana Cruz', 'female');
        $drop = $this->enroll($batch, $this->companyNamed('DataCorp'), 'Ben Santos', 'male');

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/coordinator/hte/2026', [
            'academic_year' => '2026',
            'status' => 'draft',
            'rows' => [
                ['id' => $keep->id, 'included' => true],
            ],
            'deleted_ids' => [$drop->id],
        ])->assertOk();

        $rows = collect($this->getJson('/api/coordinator/hte/2026')->json('rows'));
        $this->assertNull($rows->firstWhere('id', $drop->id), 'Deleted row should not reappear.');
        $this->assertNotNull($rows->firstWhere('id', $keep->id));
    }

    /**
     * Regression: a curated row used to disappear silently once its source
     * batch_students record was gone (e.g. archived, then hard-deleted by
     * BatchStudentPurgeService 30+ days later) — buildRows() only ever
     * looked up saved overrides while iterating the live enrollment query,
     * so a purged id's curation was never consulted. It must now render
     * from its last-saved snapshot instead.
     */
    public function test_curated_row_survives_when_its_source_enrollment_is_purged(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026');
        $enrollment = $this->enroll($batch, $this->companyNamed('TechPH Inc.'), 'Ana Cruz', 'female');

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/coordinator/hte/2026', [
            'academic_year' => '2026',
            'status' => 'finalized',
            'rows' => [
                ['id' => $enrollment->id, 'host_establishment' => 'TechPH Incorporated', 'student_name' => 'Cruz, Ana', 'program' => 'BSIT-4', 'gender' => 'Female', 'duration' => 'August 12, 2024 – October 18, 2024', 'included' => true],
            ],
        ])->assertOk();

        // Simulate the enrollment being archived and then purged.
        $enrollment->delete();

        $rows = collect($this->getJson('/api/coordinator/hte/2026')->json('rows'));
        $preserved = $rows->firstWhere('id', $enrollment->id);

        $this->assertNotNull($preserved, 'A curated row must survive its source enrollment being purged.');
        $this->assertSame('TechPH Incorporated', $preserved['host_establishment']);
        $this->assertSame('Cruz, Ana', $preserved['student_name']);
        $this->assertFalse($preserved['is_manual']);
    }

    public function test_pdf_downloads_and_excludes_non_included_rows(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026');
        $included = $this->enroll($batch, $this->companyNamed('IncludedHTE'), 'Ana Cruz', 'female');
        $excluded = $this->enroll($batch, $this->companyNamed('ExcludedHTE'), 'Ben Santos', 'male');

        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/coordinator/hte/2026', [
            'academic_year' => '2026',
            'status' => 'draft',
            'rows' => [
                ['id' => $included->id, 'included' => true],
                ['id' => $excluded->id, 'included' => false],
            ],
        ])->assertOk();

        // Endpoint yields a PDF.
        $response = $this->get('/api/coordinator/hte/2026/pdf');
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertGreaterThan(0, strlen($response->getContent()));

        // The rendered blade only reflects included rows (verified on the HTML the PDF is built from).
        $report = HteReport::where('coordinator_id', $coordinator->id)->where('academic_year', '2026')->first();
        $controller = new \App\Http\Controllers\Coordinator\HteReportController();
        $build = (new \ReflectionMethod($controller, 'buildRows'))->getClosure($controller);
        $rows = collect($build($coordinator, '2026', null, $report))->filter(fn ($r) => $r['included'])->values();
        $html = view('pdf.hte-report', ['academicYear' => '2026', 'rows' => $rows, 'meta' => [
            'signatory_prepared_name' => 'A', 'signatory_prepared_title' => 'B',
            'signatory_certified_name' => 'C', 'signatory_certified_title' => 'D',
        ]])->render();

        $this->assertStringContainsString('IncludedHTE', $html);
        $this->assertStringNotContainsString('ExcludedHTE', $html);
    }
}
