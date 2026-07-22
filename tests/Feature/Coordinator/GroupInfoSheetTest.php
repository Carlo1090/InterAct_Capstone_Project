<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\GroupInfoSheet;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupInfoSheetTest extends TestCase
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
            'start_date' => '2026-08-12',
            'end_date' => '2026-10-18',
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => $academicYear,
            'semester' => 'Internship',
            'is_active' => true,
        ]);
    }

    private function companyNamed(string $name): Company
    {
        return Company::firstOrCreate(
            ['name' => $name],
            ['address' => $name.' Address', 'head_name' => 'Head of '.$name, 'is_active' => true]
        );
    }

    /**
     * Enroll a student and give them an approved information sheet — the same
     * source the roster reads from.
     */
    private function enroll(
        Batch $batch,
        Company $company,
        string $studentName,
        string $status = 'active',
        bool $withInfoSheet = true,
    ): BatchStudent {
        $student = User::factory()->create(['role' => 'student', 'name' => $studentName]);
        StudentProfile::updateOrCreate(
            ['user_id' => $student->id],
            ['middle_name' => 'Reyes', 'year_level' => '4th Year', 'contact_number' => '09990000000']
        );
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        if ($withInfoSheet) {
            StudentInformationSheet::create([
                'student_id' => $student->id,
                'batch_id' => $batch->id,
                'personal_info' => [
                    'last_name' => 'Dela Cruz',
                    'first_name' => 'Juan',
                    'middle_name' => 'Santos',
                    'contact_number' => '09171234567',
                    'parent_guardian_name' => 'Pedro Dela Cruz',
                    'parent_guardian_contact' => '09181234567',
                ],
                'academic_info' => [
                    'program_course' => $batch->program->name,
                    'year_level' => '4th-year',
                ],
                'ojt_info' => ['company_id' => $company->id, 'host_company' => $company->name],
                'submission_status' => 'approved',
            ]);
        }

        return BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'assigned_division' => 'IT Department',
            'status' => $status,
        ]);
    }

    public function test_index_lists_only_companies_hosting_in_scope_interns(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026-2027');

        $hosting = $this->companyNamed('Bohol Quality Corporation');
        $this->enroll($batch, $hosting, 'Juan Dela Cruz');

        // Hosts nobody in scope — must never be offered.
        $this->companyNamed('Unused Company');

        // Out of scope: another department's company.
        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoordinator = $this->coordinatorFor($bsba);
        $otherBatch = $this->batchFor($bsba, $otherCoordinator, '2026-2027');
        $this->enroll($otherBatch, $this->companyNamed('Metrobank'), 'Ana Lopez');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/coordinator/group-info-sheets')->assertOk();

        $names = collect($response->json('companies'))->pluck('name')->all();

        $this->assertSame(['Bohol Quality Corporation'], $names);
        $this->assertSame(['2026-2027'], $response->json('companies.0.academic_years'));
        $this->assertContains('2026-2027', $response->json('academic_years'));
    }

    public function test_show_rosters_interns_from_their_information_sheets(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026-2027');
        $company = $this->companyNamed('Bohol Quality Corporation');
        $this->enroll($batch, $company, 'Juan Dela Cruz');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027")->assertOk();

        $response->assertJsonPath('rows.0.last_name', 'Dela Cruz')
            ->assertJsonPath('rows.0.first_name', 'Juan')
            // Middle INITIAL: the middle name's first letter, capitalized,
            // with no trailing period.
            ->assertJsonPath('rows.0.middle_initial', 'S')
            // Program CODE + prettified year, not the sheet's full program name
            // concatenated with the raw "4th-year" the individual PDF prints.
            ->assertJsonPath('rows.0.program_year', 'BSIT 4th Year')
            ->assertJsonPath('rows.0.contact_number', '09171234567')
            ->assertJsonPath('rows.0.parent_guardian_name', 'Pedro Dela Cruz')
            ->assertJsonPath('rows.0.parent_guardian_contact', '09181234567')
            ->assertJsonPath('rows.0.included', true)
            ->assertJsonPath('rows.0.is_manual', false);

        // Company block pre-fills from the company record + batch dates.
        $response->assertJsonPath('company.host_company', 'Bohol Quality Corporation')
            ->assertJsonPath('company.company_address', 'Bohol Quality Corporation Address')
            ->assertJsonPath('company.company_signatory_moa', 'Head of Bohol Quality Corporation')
            ->assertJsonPath('company.area_assigned', 'IT Department')
            ->assertJsonPath('company.ojt_start_date', '2026-08-12')
            ->assertJsonPath('company.ojt_end_date', '2026-10-18');

        // Header line defaults to the coordinator's own department, NOT the
        // reference form's hardcoded CABM text.
        $response->assertJsonPath('department_line', 'CAST Department');
    }

    public function test_roster_includes_completed_but_never_dropped_or_archived_interns(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026-2027');
        $company = $this->companyNamed('Bohol Quality Corporation');

        $this->enroll($batch, $company, 'Active Intern');
        $this->enroll($batch, $company, 'Completed Intern', 'completed');
        $this->enroll($batch, $company, 'Dropped Intern', 'dropped');

        $archived = $this->enroll($batch, $company, 'Archived Intern', 'completed');
        $archived->archived_at = now();
        $archived->save();

        Sanctum::actingAs($coordinator);

        $rows = $this->getJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027")
            ->assertOk()
            ->json('rows');

        $this->assertCount(2, $rows);
    }

    public function test_out_of_scope_company_is_forbidden(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);

        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoordinator = $this->coordinatorFor($bsba);
        $otherBatch = $this->batchFor($bsba, $otherCoordinator, '2026-2027');
        $company = $this->companyNamed('Metrobank');
        $this->enroll($otherBatch, $company, 'Ana Lopez');

        Sanctum::actingAs($coordinator);

        $this->getJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027")->assertForbidden();
        $this->getJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027/pdf")->assertForbidden();
    }

    public function test_save_persists_overrides_manual_rows_and_the_header_line(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026-2027');
        $company = $this->companyNamed('Bohol Quality Corporation');
        $enrollment = $this->enroll($batch, $company, 'Juan Dela Cruz');

        Sanctum::actingAs($coordinator);

        $response = $this->postJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027", [
            'status' => 'draft',
            'department_line' => 'College of Accountancy, Business and Management',
            'company' => [
                'host_company' => 'Bohol Quality Corporation',
                'company_address' => 'Tagbilaran City, Bohol',
                'company_signatory_moa' => 'Ms. Carmela Uy',
                'office_designation' => 'HR Manager',
                'supervisor_name' => 'Mr. Ramon Delgado',
                'supervisor_contact' => '09171112222',
                'intern_duty_schedule' => '8:00 AM - 5:00 PM',
                'area_assigned' => 'Accounting',
                'ojt_start_date' => '2026-08-12',
                'ojt_end_date' => '2026-10-18',
            ],
            'rows' => [[
                'id' => $enrollment->id,
                'last_name' => 'Edited Surname',
                'first_name' => 'Juan',
                'middle_initial' => 'S.',
                'program_year' => 'BSIT 4th Year',
                'contact_number' => '09171234567',
                'parent_guardian_name' => 'Pedro Dela Cruz',
                'parent_guardian_contact' => '09181234567',
                'included' => true,
            ]],
            'manual_rows' => [[
                'id' => 'manual-1',
                'last_name' => 'Manual',
                'first_name' => 'Intern',
                'middle_initial' => 'X.',
                'program_year' => 'BSIT 4th Year',
                'contact_number' => '09000000000',
                'parent_guardian_name' => 'Manual Parent',
                'parent_guardian_contact' => '09000000001',
                'included' => true,
            ]],
            'deleted_ids' => [],
        ])->assertOk();

        $response->assertJsonPath('rows.0.last_name', 'Edited Surname')
            ->assertJsonPath('rows.1.last_name', 'Manual')
            ->assertJsonPath('rows.1.is_manual', true)
            ->assertJsonPath('company.intern_duty_schedule', '8:00 AM - 5:00 PM')
            ->assertJsonPath('department_line', 'College of Accountancy, Business and Management');

        $this->assertDatabaseHas('group_info_sheets', [
            'coordinator_id' => $coordinator->id,
            'company_id' => $company->id,
            'academic_year' => '2026-2027',
            'status' => 'draft',
        ]);

        // Source data is never mutated by curation.
        $this->assertDatabaseHas('batch_students', ['id' => $enrollment->id, 'status' => 'active']);
    }

    public function test_deleted_row_is_tombstoned_and_stays_gone(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026-2027');
        $company = $this->companyNamed('Bohol Quality Corporation');
        $keep = $this->enroll($batch, $company, 'Kept Intern');
        $drop = $this->enroll($batch, $company, 'Removed Intern');

        Sanctum::actingAs($coordinator);

        $rows = $this->postJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027", [
            'status' => 'draft',
            'company' => [],
            'rows' => [],
            'deleted_ids' => [$drop->id],
        ])->assertOk()->json('rows');

        $this->assertCount(1, $rows);
        $this->assertSame($keep->id, $rows[0]['id']);
    }

    /**
     * The exact failure already fixed in HteReportController: a curated row
     * whose source enrollment is later purged must keep rendering from its
     * last-saved snapshot instead of silently vanishing from the document.
     */
    public function test_curated_row_survives_when_its_source_enrollment_is_purged(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026-2027');
        $company = $this->companyNamed('Bohol Quality Corporation');
        $staying = $this->enroll($batch, $company, 'Staying Intern');
        $purged = $this->enroll($batch, $company, 'Purged Intern');

        GroupInfoSheet::create([
            'coordinator_id' => $coordinator->id,
            'company_id' => $company->id,
            'academic_year' => '2026-2027',
            'status' => 'draft',
            'sheet_data' => [
                'header' => ['department_line' => 'CAST Department'],
                'company' => [],
                'rows' => [
                    ['id' => $purged->id, 'last_name' => 'Snapshot', 'first_name' => 'Survivor', 'included' => true],
                ],
                'manual_rows' => [],
                'deleted_ids' => [],
            ],
        ]);

        $purged->delete();

        Sanctum::actingAs($coordinator);

        $rows = $this->getJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027")
            ->assertOk()
            ->json('rows');

        $this->assertCount(2, $rows);
        $this->assertSame($staying->id, $rows[0]['id']);
        $this->assertSame('Snapshot', $rows[1]['last_name']);
        // Not manual — a later save keeps writing it back under its own id.
        $this->assertFalse($rows[1]['is_manual']);
    }

    public function test_pdf_downloads_only_included_rows(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026-2027');
        $company = $this->companyNamed('Bohol Quality Corporation');
        $enrollment = $this->enroll($batch, $company, 'Juan Dela Cruz');

        GroupInfoSheet::create([
            'coordinator_id' => $coordinator->id,
            'company_id' => $company->id,
            'academic_year' => '2026-2027',
            'status' => 'finalized',
            'sheet_data' => [
                'header' => ['department_line' => 'CAST Department'],
                'company' => [],
                'rows' => [['id' => $enrollment->id, 'included' => false]],
                'manual_rows' => [],
                'deleted_ids' => [],
            ],
        ]);

        Sanctum::actingAs($coordinator);

        $response = $this->get("/api/coordinator/group-info-sheets/{$company->id}/2026-2027/pdf")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString(
            'group-student-information-sheet-bohol-quality-corporation-2026-2027.pdf',
            (string) $response->headers->get('content-disposition')
        );
    }

    /**
     * The middle initial is always a single capital letter, whatever case or
     * leading punctuation the student typed their middle name in.
     */
    public function test_middle_initial_is_a_single_capital_letter(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026-2027');
        $company = $this->companyNamed('Bohol Quality Corporation');
        $enrollment = $this->enroll($batch, $company, 'Juan Dela Cruz');

        $sheet = StudentInformationSheet::where('student_id', $enrollment->student_id)->firstOrFail();
        // Clear the profile so only the sheet's own middle name is in play.
        $enrollment->student->studentProfile->update(['middle_name' => null]);

        Sanctum::actingAs($coordinator);

        foreach (['bautista' => 'B', ' de la Cruz' => 'D', 'ñuñez' => 'Ñ', '' => ''] as $middle => $expected) {
            $sheet->update(['personal_info' => [
                ...$sheet->personal_info,
                'middle_name' => $middle,
            ]]);

            $this->getJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027")
                ->assertOk()
                ->assertJsonPath('rows.0.middle_initial', $expected);
        }

        // With the sheet's middle name still blank, the student profile is the
        // fallback — the sheet is preferred, not the only source.
        $enrollment->student->studentProfile->update(['middle_name' => 'Reyes']);

        $this->getJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027")
            ->assertOk()
            ->assertJsonPath('rows.0.middle_initial', 'R');
    }

    /**
     * A blank saved cell must NOT freeze out what the student later types on
     * their own information sheet — only a real coordinator edit overrides.
     */
    public function test_an_empty_saved_override_never_masks_the_students_own_sheet(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026-2027');
        $company = $this->companyNamed('Bohol Quality Corporation');
        $enrollment = $this->enroll($batch, $company, 'Juan Dela Cruz');

        $sheet = StudentInformationSheet::where('student_id', $enrollment->student_id)->firstOrFail();

        // The student had not filled in their guardian yet, and the
        // coordinator saved the group sheet in that state.
        $sheet->update(['personal_info' => [
            ...$sheet->personal_info,
            'parent_guardian_name' => '',
            'parent_guardian_contact' => '',
        ]]);

        Sanctum::actingAs($coordinator);

        $this->postJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027", [
            'status' => 'draft',
            'company' => [],
            'rows' => [[
                'id' => $enrollment->id,
                'last_name' => 'Dela Cruz',
                'first_name' => 'Juan',
                'parent_guardian_name' => '',
                'parent_guardian_contact' => '',
                'included' => true,
            ]],
            'deleted_ids' => [],
        ])->assertOk();

        // The student then fills their guardian in on their own sheet.
        $sheet->update(['personal_info' => [
            ...$sheet->personal_info,
            'parent_guardian_name' => 'Pedro Dela Cruz',
            'parent_guardian_contact' => '09181234567',
        ]]);

        $this->getJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027")
            ->assertOk()
            ->assertJsonPath('rows.0.parent_guardian_name', 'Pedro Dela Cruz')
            ->assertJsonPath('rows.0.parent_guardian_contact', '09181234567');
    }

    public function test_a_non_empty_override_still_wins_over_the_students_sheet(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026-2027');
        $company = $this->companyNamed('Bohol Quality Corporation');
        $enrollment = $this->enroll($batch, $company, 'Juan Dela Cruz');

        Sanctum::actingAs($coordinator);

        $this->postJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027", [
            'status' => 'draft',
            'company' => [],
            'rows' => [[
                'id' => $enrollment->id,
                'parent_guardian_name' => 'Corrected Guardian',
                'included' => true,
            ]],
            'deleted_ids' => [],
        ])->assertOk();

        $this->getJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027")
            ->assertOk()
            ->assertJsonPath('rows.0.parent_guardian_name', 'Corrected Guardian')
            // Untouched cells keep tracking the student's sheet.
            ->assertJsonPath('rows.0.first_name', 'Juan');
    }

    public function test_a_non_coordinator_cannot_reach_the_sheet(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, '2026-2027');
        $company = $this->companyNamed('Bohol Quality Corporation');
        $enrollment = $this->enroll($batch, $company, 'Juan Dela Cruz');

        Sanctum::actingAs($enrollment->student);

        $this->getJson("/api/coordinator/group-info-sheets/{$company->id}/2026-2027")->assertForbidden();
    }
}
