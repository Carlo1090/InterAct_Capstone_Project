<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\User;
use App\Support\SippAnnexLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The page geometry of the two official SIPP annexes, measured from the
 * reference .docx files in docs/reference/.
 *
 * These exist because the defect they pin was invisible to every other test:
 * the Annual SIPP report had NO setPaper() call at all, so it rendered on
 * dompdf's A4 PORTRAIT default while its reference is 8.5in x 13in LANDSCAPE,
 * and the HTE report asked for A4 landscape (842 x 595pt) against the same
 * reference's 935.55 x 612.1. Both downloads returned 200 with a valid PDF, so
 * "the endpoint works" was true the whole time and the pages were still wrong.
 *
 * The same class of assertion already guards the weekly activity log
 * (`test_the_pdf_is_us_letter_and_fits_on_one_page`), and it is the only kind
 * that notices a paper regression coming back.
 */
class SippAnnexPageGeometryTest extends TestCase
{
    use RefreshDatabase;

    /** The MediaBox dompdf writes for the reference's landscape long bond. */
    private const EXPECTED_MEDIABOX = 'MediaBox [0.000 0.000 935.550 612.100]';

    private function programFor(string $code = 'BSIT', string $deptCode = 'CAST'): Program
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
            'academic_year' => '2026',
            'semester' => 'Internship',
            'start_date' => now()->subMonths(2),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'is_active' => true,
        ]);
    }

    private function enrolledStudent(Batch $batch): User
    {
        $student = User::factory()->create(['role' => 'student', 'program_id' => $batch->program_id]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company = Company::create(['name' => 'Co '.uniqid(), 'address' => 'Addr', 'is_active' => true]);
        CompanySupervisor::create([
            'company_id' => $company->id,
            'user_id' => $supervisor->id,
            'position' => 'Supervisor',
        ]);

        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        return $student;
    }

    public function test_the_annual_sipp_report_is_landscape_long_bond(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);
        $student = $this->enrolledStudent($batch);

        JournalEntry::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'entry_date' => now()->subDays(3)->toDateString(),
            'status' => 'submitted',
            'content' => [
                'daily_accomplishment' => 'Worked.',
                'issues_concerns' => 'The queue built up after lunch.',
                'solutions' => 'Prioritised the counter first.',
                'recommendations' => 'A second encoder at peak.',
            ],
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $pdf = $this->get("/api/coordinator/annual-sipp/{$program->id}/pdf?academic_year=2026")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            self::EXPECTED_MEDIABOX,
            $pdf,
            'Annex C is not landscape long bond — dompdf most likely fell back to A4 portrait.'
        );
    }

    public function test_the_hte_report_is_landscape_long_bond(): void
    {
        $program = $this->programFor();
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);
        $this->enrolledStudent($batch);

        Sanctum::actingAs($coordinator, ['*']);

        $pdf = $this->get('/api/coordinator/hte/2026/pdf')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            self::EXPECTED_MEDIABOX,
            $pdf,
            'Annex D is not landscape long bond — it was A4 landscape before this was measured.'
        );
    }

    /**
     * The measurements themselves, so a "tidy up" that rounds 935.55 to 936 or
     * re-derives a column width has to say so out loud.
     */
    public function test_the_measured_columns_match_the_reference_docx(): void
    {
        // 18711 x 12242 twips, / 20.
        $this->assertSame(935.55, SippAnnexLayout::PAGE_LONG_EDGE);
        $this->assertSame(612.1, SippAnnexLayout::PAGE_SHORT_EDGE);

        // 1440 twips.
        $this->assertSame(72.0, SippAnnexLayout::MARGIN);

        // 6769 / 5225 / 3513 twips.
        $this->assertSame([338.45, 261.25, 175.65], SippAnnexLayout::ANNEX_C_COLUMNS);

        // 4957 / 3827 / 1797 / 1888 / 2753 twips.
        $this->assertSame([247.85, 191.35, 89.85, 94.4, 137.65], SippAnnexLayout::ANNEX_D_COLUMNS);

        // Both tables fit inside the 791.55pt content column.
        $this->assertLessThanOrEqual(
            SippAnnexLayout::CONTENT_WIDTH,
            SippAnnexLayout::tableWidth(SippAnnexLayout::ANNEX_C_COLUMNS)
        );
        $this->assertLessThanOrEqual(
            SippAnnexLayout::CONTENT_WIDTH,
            SippAnnexLayout::tableWidth(SippAnnexLayout::ANNEX_D_COLUMNS)
        );

        // A written width is content-box: the reference column minus its own
        // horizontal padding, or dompdf widens every table by that padding.
        $this->assertSame(327.65, SippAnnexLayout::contentWidth(338.45));
    }
}
