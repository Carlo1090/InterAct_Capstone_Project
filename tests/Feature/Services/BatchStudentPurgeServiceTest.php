<?php

namespace Tests\Feature\Services;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use App\Services\BatchStudentPurgeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchStudentPurgeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function batch(): Batch
    {
        $department = Department::firstOrCreate(['code' => 'CABM-B'], ['name' => 'CABM-B', 'is_active' => true]);
        $program = Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => 'BSA'],
            ['name' => 'BSA', 'is_active' => true]
        );
        $coordinator = User::factory()->create(['role' => 'coordinator']);

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

    private function archivedRow(Batch $batch, ?Carbon $archivedAt, string $status = 'dropped'): BatchStudent
    {
        $company = Company::create(['name' => 'Co '.uniqid(), 'address' => 'Bohol', 'is_active' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $batch->program_id]);

        $row = BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => $status,
        ]);

        if ($archivedAt !== null) {
            $row->archived_at = $archivedAt;
            $row->save();
        }

        return $row;
    }

    public function test_purges_a_row_archived_31_days_ago(): void
    {
        $batch = $this->batch();
        $row = $this->archivedRow($batch, now()->subDays(31));

        $result = (new BatchStudentPurgeService)->purgeExpiredArchives();

        $this->assertSame(1, $result['purged']);
        $this->assertDatabaseMissing('batch_students', ['id' => $row->id]);
    }

    public function test_purges_a_row_archived_exactly_30_days_ago(): void
    {
        $batch = $this->batch();
        $row = $this->archivedRow($batch, now()->subDays(30));

        $result = (new BatchStudentPurgeService)->purgeExpiredArchives();

        $this->assertSame(1, $result['purged']);
        $this->assertDatabaseMissing('batch_students', ['id' => $row->id]);
    }

    public function test_keeps_a_row_archived_29_days_ago(): void
    {
        $batch = $this->batch();
        $row = $this->archivedRow($batch, now()->subDays(29));

        $result = (new BatchStudentPurgeService)->purgeExpiredArchives();

        $this->assertSame(0, $result['purged']);
        $this->assertDatabaseHas('batch_students', ['id' => $row->id]);
    }

    public function test_ignores_non_archived_rows_of_any_status(): void
    {
        $batch = $this->batch();
        $dropped = $this->archivedRow($batch, null, 'dropped');
        $completed = $this->archivedRow($batch, null, 'completed');
        $active = $this->archivedRow($batch, null, 'active');

        $result = (new BatchStudentPurgeService)->purgeExpiredArchives();

        $this->assertSame(0, $result['purged']);
        $this->assertDatabaseHas('batch_students', ['id' => $dropped->id]);
        $this->assertDatabaseHas('batch_students', ['id' => $completed->id]);
        $this->assertDatabaseHas('batch_students', ['id' => $active->id]);
    }

    /**
     * A 'completed' row is the only thing keeping a legacy student (no
     * approved info sheet, no other qualifying row) past
     * User::isInfoSheetGated()'s fallback check — purging it would silently
     * re-gate them, so it must be protected instead of deleted.
     */
    public function test_protects_a_completed_row_that_is_a_students_sole_gate_clearing_signal(): void
    {
        $batch = $this->batch();
        $row = $this->archivedRow($batch, now()->subDays(31), 'completed');

        $result = (new BatchStudentPurgeService)->purgeExpiredArchives();

        $this->assertSame(0, $result['purged']);
        $this->assertSame(1, $result['protected']);
        $this->assertDatabaseHas('batch_students', ['id' => $row->id]);
    }

    public function test_purges_a_completed_row_once_the_student_has_an_approved_info_sheet(): void
    {
        $batch = $this->batch();
        $row = $this->archivedRow($batch, now()->subDays(31), 'completed');

        StudentInformationSheet::create([
            'student_id' => $row->student_id,
            'batch_id' => $batch->id,
            'personal_info' => ['last_name' => 'Cruz', 'first_name' => 'Juan'],
            'academic_info' => ['program_course' => 'BSA'],
            'ojt_info' => ['host_company' => 'Test Co.'],
            'submission_status' => 'approved',
        ]);

        $result = (new BatchStudentPurgeService)->purgeExpiredArchives();

        $this->assertSame(1, $result['purged']);
        $this->assertSame(0, $result['protected']);
        $this->assertDatabaseMissing('batch_students', ['id' => $row->id]);
    }

    public function test_purges_a_completed_row_when_the_student_has_another_qualifying_enrollment(): void
    {
        $batch = $this->batch();
        $row = $this->archivedRow($batch, now()->subDays(31), 'completed');

        BatchStudent::create([
            'batch_id' => $this->batch()->id,
            'student_id' => $row->student_id,
            'company_id' => Company::create(['name' => 'Co '.uniqid(), 'address' => 'Bohol', 'is_active' => true])->id,
            'supervisor_id' => User::factory()->create(['role' => 'supervisor'])->id,
            'status' => 'active',
        ]);

        $result = (new BatchStudentPurgeService)->purgeExpiredArchives();

        $this->assertSame(1, $result['purged']);
        $this->assertSame(0, $result['protected']);
        $this->assertDatabaseMissing('batch_students', ['id' => $row->id]);
    }

    public function test_never_protects_a_dropped_row_since_it_never_counted_toward_gating(): void
    {
        $batch = $this->batch();
        $row = $this->archivedRow($batch, now()->subDays(31), 'dropped');

        $result = (new BatchStudentPurgeService)->purgeExpiredArchives();

        $this->assertSame(1, $result['purged']);
        $this->assertSame(0, $result['protected']);
        $this->assertDatabaseMissing('batch_students', ['id' => $row->id]);
    }

    public function test_now_override_treats_the_given_date_as_the_cutoff_basis(): void
    {
        $batch = $this->batch();
        $row = $this->archivedRow($batch, Carbon::parse('2026-01-01'));

        // Only 5 days after archiving by the real clock, but 40 days past
        // archiving relative to the overridden "now".
        $result = (new BatchStudentPurgeService)->purgeExpiredArchives(Carbon::parse('2026-02-10'));

        $this->assertSame(1, $result['purged']);
        $this->assertDatabaseMissing('batch_students', ['id' => $row->id]);
    }
}
