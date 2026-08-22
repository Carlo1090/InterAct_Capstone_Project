<?php

namespace Tests\Feature\Services;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanyGeofence;
use App\Models\Department;
use App\Models\DtrSession;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use App\Services\BatchStudentPurgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regression guard for the single most dangerous mistake this feature could
 * have made.
 *
 * BatchStudentPurgeService HARD-deletes archived batch_students rows after 30
 * days. No table in the schema carries a foreign key to batch_students.id
 * precisely so that purge can never orphan history — journals and weekly logs
 * key off student_id + batch_id instead. dtr_sessions follows the same rule.
 *
 * Had dtr_sessions been given the obvious-looking
 * foreignId('batch_student_id')->cascadeOnDelete(), the nightly purge would
 * silently erase a student's entire attendance record 30 days after their
 * enrollment row was archived — with no error, and no way to reconstruct the
 * hours they had banked.
 */
class DtrPurgeSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_purging_an_archived_enrollment_leaves_its_dtr_sessions_intact(): void
    {
        $department = Department::create(['name' => 'CAST', 'code' => 'CAST', 'is_active' => true]);
        $program = Program::create([
            'department_id' => $department->id,
            'name' => 'BS Information Technology',
            'code' => 'BSIT',
            'is_active' => true,
        ]);
        $coordinator = User::factory()->create(['role' => 'coordinator', 'dtr_enabled' => true]);

        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Batch 2026',
            'start_date' => now()->subMonths(6),
            'end_date' => now()->subMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026-2027',
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Tagbilaran', 'is_active' => true]);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        // An approved sheet, so the purge's "sole gate-clearing row"
        // protection does not kick in and skip the delete we are testing.
        StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'submission_status' => 'approved',
            'personal_info' => [],
            'academic_info' => [],
            'ojt_info' => [],
        ]);

        $enrollment = BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'completed',
        ]);

        // archived_at is deliberately excluded from #[Fillable], so it must
        // be set by direct assignment — ->update() would silently no-op.
        $enrollment->archived_at = Carbon::now()->subDays(45);
        $enrollment->save();

        $geofence = CompanyGeofence::create([
            'company_id' => $company->id,
            'label' => 'Main Office',
            'latitude' => 9.6496,
            'longitude' => 124.1264,
            'radius_meters' => 150,
            'is_active' => true,
        ]);

        DtrSession::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'geofence_id' => $geofence->id,
            'work_date' => now()->subMonths(2)->toDateString(),
            'time_in' => now()->subMonths(2),
            'time_in_lat' => 9.6496,
            'time_in_lng' => 124.1264,
            'time_out' => now()->subMonths(2)->addHours(8),
            'time_out_lat' => 9.6496,
            'time_out_lng' => 124.1264,
            'minutes_worked' => 480,
            'status' => 'closed',
        ]);

        $result = app(BatchStudentPurgeService::class)->purgeExpiredArchives();

        $this->assertSame(1, $result['purged']);
        $this->assertDatabaseMissing('batch_students', ['id' => $enrollment->id]);

        // The attendance record survives the purge of its enrollment row.
        $this->assertDatabaseCount('dtr_sessions', 1);
        $this->assertDatabaseHas('dtr_sessions', [
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'minutes_worked' => 480,
        ]);
    }

    /**
     * The complementary guarantee: retiring a clock-in site must not take the
     * punches taken there with it. geofence_id is nullOnDelete, so even a
     * hard delete of the fence leaves the session rows in place.
     */
    public function test_deleting_a_geofence_nulls_the_reference_rather_than_deleting_sessions(): void
    {
        $department = Department::create(['name' => 'CAST', 'code' => 'CAST', 'is_active' => true]);
        $program = Program::create([
            'department_id' => $department->id,
            'name' => 'BS Information Technology',
            'code' => 'BSIT',
            'is_active' => true,
        ]);
        $coordinator = User::factory()->create(['role' => 'coordinator', 'dtr_enabled' => true]);

        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Batch 2026',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026-2027',
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Tagbilaran', 'is_active' => true]);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        $geofence = CompanyGeofence::create([
            'company_id' => $company->id,
            'label' => 'Main Office',
            'latitude' => 9.6496,
            'longitude' => 124.1264,
            'radius_meters' => 150,
            'is_active' => true,
        ]);

        $session = DtrSession::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'geofence_id' => $geofence->id,
            'work_date' => now()->toDateString(),
            'time_in' => now()->subHours(8),
            'time_in_lat' => 9.6496,
            'time_in_lng' => 124.1264,
            'time_out' => now(),
            'time_out_lat' => 9.6496,
            'time_out_lng' => 124.1264,
            'minutes_worked' => 480,
            'status' => 'closed',
        ]);

        $geofence->delete();

        $this->assertDatabaseHas('dtr_sessions', [
            'id' => $session->id,
            'geofence_id' => null,
            'minutes_worked' => 480,
        ]);
    }
}
