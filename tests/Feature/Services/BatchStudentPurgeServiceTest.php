<?php

namespace Tests\Feature\Services;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
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
}
