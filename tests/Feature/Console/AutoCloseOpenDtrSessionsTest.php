<?php

namespace Tests\Feature\Console;

use App\Console\Commands\AutoCloseOpenDtrSessions;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanyGeofence;
use App\Models\CompanySupervisor;
use App\Models\Department;
use App\Models\DtrSession;
use App\Models\Program;
use App\Models\User;
use App\Services\DtrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The forgotten clock-out.
 *
 * Before this command a session left open simply never ended: the next
 * morning's scan was read as a clock-OUT of the previous day, so the student
 * believed they had timed in, and their evening scan opened yet another
 * overnight session. Every test here pins one half of stopping that.
 */
class AutoCloseOpenDtrSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const SITE_LAT = 9.6496;

    private const SITE_LNG = 124.1264;

    /**
     * @return array{student: User, batch: Batch, geofence: CompanyGeofence}
     */
    private function world(): array
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
            'end_date' => now()->addMonths(3),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026-2027',
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company = Company::create(['name' => 'Acme Corp', 'address' => 'Tagbilaran', 'is_active' => true]);
        CompanySupervisor::create([
            'company_id' => $company->id,
            'user_id' => $supervisor->id,
            'position' => 'Supervisor',
        ]);

        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        $geofence = CompanyGeofence::create([
            'company_id' => $company->id,
            'created_by' => $supervisor->id,
            'label' => 'Main Office',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'radius_meters' => 150,
            'is_active' => true,
        ]);

        return compact('student', 'batch', 'geofence');
    }

    /**
     * @param  array{student: User, batch: Batch, geofence: CompanyGeofence}  $world
     */
    private function openSessionAt(array $world, string $timeIn): DtrSession
    {
        $at = Carbon::parse($timeIn);

        return DtrSession::create([
            'student_id' => $world['student']->id,
            'batch_id' => $world['batch']->id,
            'geofence_id' => $world['geofence']->id,
            'work_date' => $at->toDateString(),
            'time_in' => $at,
            'time_in_lat' => self::SITE_LAT,
            'time_in_lng' => self::SITE_LNG,
            'status' => 'open',
        ]);
    }

    public function test_it_closes_a_session_left_open_past_the_stale_threshold(): void
    {
        $world = $this->world();
        $session = $this->openSessionAt($world, '2026-08-20 08:00:00');

        // 13 hours later — past the 12-hour threshold.
        $this->artisan('dtr:auto-close-sessions', ['--now' => '2026-08-20 21:00:00'])
            ->assertSuccessful();

        $session->refresh();

        $this->assertSame('flagged', $session->status);
        $this->assertSame(DtrService::DEFAULT_SHIFT_MINUTES, $session->minutes_worked);
        // No clock-out was observed, so none is invented.
        $this->assertNull($session->time_out);
        // A system close, not a human correction.
        $this->assertNull($session->adjusted_by);
        $this->assertStringContainsString('no clock-out was scanned', $session->adjustment_reason);
    }

    /**
     * The assumed shift is the SUPERVISOR'S starting number, never credit. If
     * this ever counted on its own, an intern who never clocked out would bank
     * a full day for turning up once.
     */
    public function test_the_assumed_hours_do_not_count_toward_required_hours(): void
    {
        $world = $this->world();
        $this->openSessionAt($world, '2026-08-20 08:00:00');

        $this->artisan('dtr:auto-close-sessions', ['--now' => '2026-08-20 21:00:00']);

        $this->assertSame(0, app(DtrService::class)
            ->minutesCompleted($world['student']->id, $world['batch']->id));
    }

    /**
     * The whole point of closing it: dtr_sessions.open_session_key is uniquely
     * indexed, so a session left open blocks every future clock-in. A close
     * that did not release the key would end that student's DTR permanently.
     */
    public function test_closing_releases_the_student_to_clock_in_again(): void
    {
        $world = $this->world();
        $this->openSessionAt($world, '2026-08-20 08:00:00');

        $this->artisan('dtr:auto-close-sessions', ['--now' => '2026-08-20 21:00:00']);

        $this->assertSame(
            0,
            DtrSession::where('student_id', $world['student']->id)->whereNotNull('open_session_key')->count(),
        );

        // Proven by actually opening another one — the unique index would
        // throw if the key were still held.
        $this->openSessionAt($world, '2026-08-21 08:00:00');
        $this->assertDatabaseCount('dtr_sessions', 2);
    }

    public function test_the_student_is_notified(): void
    {
        $world = $this->world();
        $this->openSessionAt($world, '2026-08-20 08:00:00');

        $this->artisan('dtr:auto-close-sessions', ['--now' => '2026-08-20 21:00:00']);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $world['student']->id,
            'title' => AutoCloseOpenDtrSessions::TITLE,
            // notifications.type is a strict enum; anything else fails a CHECK.
            'type' => 'in_app',
            'is_read' => false,
        ]);
    }

    /**
     * A student still at work must not be timed out from under them. This is
     * the boundary that makes an hourly schedule safe.
     */
    public function test_a_session_inside_the_threshold_is_left_alone(): void
    {
        $world = $this->world();
        $session = $this->openSessionAt($world, '2026-08-20 08:00:00');

        // 9 hours in — a long but entirely ordinary shift.
        $this->artisan('dtr:auto-close-sessions', ['--now' => '2026-08-20 17:00:00'])
            ->expectsOutputToContain('Auto-closed 0 session(s).')
            ->assertSuccessful();

        $this->assertSame('open', $session->refresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    /**
     * CronController invokes this on EVERY ping with no marker. That is only
     * safe if a second run is a no-op — otherwise an hourly pinger would
     * re-notify the same student once an hour, all day.
     */
    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $world = $this->world();
        $session = $this->openSessionAt($world, '2026-08-20 08:00:00');

        $this->artisan('dtr:auto-close-sessions', ['--now' => '2026-08-20 21:00:00']);
        $firstReason = $session->refresh()->adjustment_reason;

        $this->artisan('dtr:auto-close-sessions', ['--now' => '2026-08-20 22:00:00'])
            ->expectsOutputToContain('Auto-closed 0 session(s).');

        $this->assertSame($firstReason, $session->refresh()->adjustment_reason);
        $this->assertDatabaseCount('notifications', 1);
    }

    /**
     * A supervisor's correction is the authority, and it must survive. Once
     * adjusted the session is 'closed', so a later run cannot reach back and
     * overwrite the real number with the assumed one.
     */
    public function test_it_never_overwrites_a_supervisors_adjustment(): void
    {
        $world = $this->world();
        $session = $this->openSessionAt($world, '2026-08-20 08:00:00');

        $this->artisan('dtr:auto-close-sessions', ['--now' => '2026-08-20 21:00:00']);

        $session->refresh()->fill([
            'minutes_worked' => 300,
            'status' => 'closed',
            'adjustment_reason' => 'Left at 1pm, confirmed.',
        ])->save();

        $this->artisan('dtr:auto-close-sessions', ['--now' => '2026-08-21 21:00:00']);

        $session->refresh();
        $this->assertSame(300, $session->minutes_worked);
        $this->assertSame('closed', $session->status);
    }
}
