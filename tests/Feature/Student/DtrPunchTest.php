<?php

namespace Tests\Feature\Student;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanyGeofence;
use App\Models\CompanySupervisor;
use App\Models\Department;
use App\Models\DtrSession;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use App\Services\DtrService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DtrPunchTest extends TestCase
{
    use RefreshDatabase;

    /** The reference point for every fence in this file (Tagbilaran, Bohol). */
    private const SITE_LAT = 9.6496;

    private const SITE_LNG = 124.1264;

    /**
     * A complete DTR-ready world: department, program, DTR-enabled
     * coordinator, batch, company with a login supervisor, an active
     * enrollment, an approved info sheet (so the infosheet.approved gate
     * lets the student through), and an active geofence.
     *
     * @return array{student: User, supervisor: User, batch: Batch, company: Company, geofence: CompanyGeofence, coordinator: User}
     */
    private function world(bool $dtrEnabled = true): array
    {
        $department = Department::create(['name' => 'CAST', 'code' => 'CAST', 'is_active' => true]);
        $program = Program::create([
            'department_id' => $department->id,
            'name' => 'BS Information Technology',
            'code' => 'BSIT',
            'is_active' => true,
        ]);

        $coordinator = User::factory()->create([
            'role' => 'coordinator',
            'dtr_enabled' => $dtrEnabled,
        ]);

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

        StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'submission_status' => 'approved',
            'personal_info' => ['first_name' => 'Test', 'last_name' => 'Student'],
            'academic_info' => [],
            'ojt_info' => [],
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

        return compact('student', 'supervisor', 'batch', 'company', 'geofence', 'coordinator');
    }

    public function test_a_student_can_clock_in_from_inside_the_geofence(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['student']);

        $response = $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'accuracy' => 12,
        ]);

        $response->assertOk()->assertJsonPath('action', 'clocked_in');

        $this->assertDatabaseHas('dtr_sessions', [
            'student_id' => $world['student']->id,
            'batch_id' => $world['batch']->id,
            'geofence_id' => $world['geofence']->id,
            'status' => 'open',
        ]);
    }

    public function test_a_punch_from_outside_the_radius_is_rejected(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['student']);

        // ~1.1km north of the site — well outside the 150m fence.
        $response = $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT + 0.01,
            'longitude' => self::SITE_LNG,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('dtr_sessions', 0);
    }

    /**
     * The core toggle: a second scan at the SAME site closes the session
     * rather than opening a duplicate one.
     */
    public function test_scanning_again_at_the_same_site_clocks_out_and_banks_the_minutes(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['student']);

        Carbon::setTestNow(Carbon::parse('2026-08-20 08:00:00'));
        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertOk()->assertJsonPath('action', 'clocked_in');

        Carbon::setTestNow(Carbon::parse('2026-08-20 17:00:00'));
        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertOk()->assertJsonPath('action', 'clocked_out');

        Carbon::setTestNow();

        $this->assertDatabaseCount('dtr_sessions', 1);
        $this->assertDatabaseHas('dtr_sessions', [
            'student_id' => $world['student']->id,
            'status' => 'closed',
            'minutes_worked' => 540, // 9 hours
        ]);
    }

    /**
     * A student cannot hold two open sessions at once. Scanning a DIFFERENT
     * site while one is open is refused rather than silently closing the
     * first, which would invent a clock-out time nobody observed.
     */
    public function test_an_open_session_at_another_site_blocks_a_new_clock_in(): void
    {
        $world = $this->world();

        $otherSite = CompanyGeofence::create([
            'company_id' => $world['company']->id,
            'created_by' => $world['supervisor']->id,
            'label' => 'Warehouse Annex',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'radius_meters' => 150,
            'is_active' => true,
        ]);

        Sanctum::actingAs($world['student']);

        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertOk();

        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $otherSite->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertStatus(422);

        $this->assertDatabaseCount('dtr_sessions', 1);
    }

    /**
     * THE CASCADE THIS EXISTS TO STOP.
     *
     * A student forgets to scan out and scans the next morning expecting to
     * time IN. Read as a plain toggle that is a clock-OUT of yesterday: they
     * walk away believing they are clocked in, work all day uncounted, and
     * their evening scan opens ANOTHER overnight session — the failure
     * compounds a day at a time.
     *
     * So a session past DtrService::STALE_SESSION_MINUTES is closed out of the
     * way and the scan opens a fresh one. The morning scan does what the
     * student expects, and it does so without the nightly command having run —
     * which matters, because the API sleeps on an idle free tier.
     */
    public function test_a_stale_session_is_auto_closed_and_the_scan_reads_as_a_fresh_clock_in(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['student']);

        Carbon::setTestNow(Carbon::parse('2026-08-20 08:00:00'));
        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertOk()->assertJsonPath('action', 'clocked_in');

        // Scanned the following morning — 25 hours later.
        Carbon::setTestNow(Carbon::parse('2026-08-21 09:00:00'));
        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])
            ->assertOk()
            // NOT clocked_out. This is the whole point.
            ->assertJsonPath('action', 'clocked_in')
            ->assertJsonPath('auto_closed_previous', true);

        Carbon::setTestNow();

        $sessions = DtrSession::orderBy('time_in')->get();

        $this->assertCount(2, $sessions);

        // Yesterday: closed for review, with the assumed shift as the
        // supervisor's starting number and NO invented clock-out time.
        $this->assertSame('flagged', $sessions[0]->status);
        $this->assertSame(DtrService::DEFAULT_SHIFT_MINUTES, $sessions[0]->minutes_worked);
        $this->assertNull($sessions[0]->time_out);

        // Today: genuinely clocked in.
        $this->assertSame('open', $sessions[1]->status);
        $this->assertSame('2026-08-21', $sessions[1]->work_date->toDateString());

        // And none of it counts until the supervisor confirms it.
        $this->assertSame(0, app(DtrService::class)
            ->minutesCompleted($world['student']->id, $world['batch']->id));
    }

    /**
     * The boundary on the other side: a long-but-real shift inside the stale
     * window still clocks out normally and banks its real minutes. Getting
     * this wrong would silently convert every 10-hour day into a flagged row.
     */
    public function test_a_long_but_plausible_shift_still_clocks_out_normally(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['student']);

        Carbon::setTestNow(Carbon::parse('2026-08-20 07:00:00'));
        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertOk();

        // 11 hours — long, but under the 12-hour threshold.
        Carbon::setTestNow(Carbon::parse('2026-08-20 18:00:00'));
        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertOk()->assertJsonPath('action', 'clocked_out');

        Carbon::setTestNow();

        $this->assertDatabaseCount('dtr_sessions', 1);

        $session = DtrSession::first();
        $this->assertSame('closed', $session->status);
        $this->assertSame(660, $session->minutes_worked);
    }

    public function test_a_student_cannot_clock_in_at_another_companys_site(): void
    {
        $world = $this->world();

        $otherCompany = Company::create(['name' => 'Other Inc', 'address' => 'Elsewhere', 'is_active' => true]);
        $foreignSite = CompanyGeofence::create([
            'company_id' => $otherCompany->id,
            'label' => 'Their Office',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'radius_meters' => 150,
            'is_active' => true,
        ]);

        Sanctum::actingAs($world['student']);

        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $foreignSite->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertStatus(403);
    }

    public function test_a_retired_site_no_longer_accepts_punches(): void
    {
        $world = $this->world();
        $world['geofence']->update(['is_active' => false]);

        Sanctum::actingAs($world['student']);

        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertStatus(404);
    }

    /**
     * The coordinator opt-out. A student whose coordinator left DTR off must
     * be shut out of every DTR surface, not merely have the nav item hidden.
     */
    public function test_a_student_whose_coordinator_disabled_dtr_is_locked_out(): void
    {
        $world = $this->world(dtrEnabled: false);
        Sanctum::actingAs($world['student']);

        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertStatus(403);

        $this->getJson('/api/student/dtr')
            ->assertOk()
            ->assertJsonPath('enabled', false);
    }

    public function test_the_dashboard_reports_null_hours_when_dtr_is_disabled(): void
    {
        $world = $this->world(dtrEnabled: false);
        Sanctum::actingAs($world['student']);

        $this->getJson('/api/student/dashboard')
            ->assertOk()
            ->assertJsonPath('progress.hours', null)
            // The pre-existing metrics are untouched by this feature.
            ->assertJsonStructure(['progress' => ['weekly_reports_approved_percent', 'ojt_duration_percent']]);
    }

    public function test_the_dashboard_reports_banked_hours_against_required_hours(): void
    {
        $world = $this->world();

        DtrSession::create([
            'student_id' => $world['student']->id,
            'batch_id' => $world['batch']->id,
            'geofence_id' => $world['geofence']->id,
            'work_date' => now()->toDateString(),
            'time_in' => now()->subHours(8),
            'time_in_lat' => self::SITE_LAT,
            'time_in_lng' => self::SITE_LNG,
            'time_out' => now(),
            'time_out_lat' => self::SITE_LAT,
            'time_out_lng' => self::SITE_LNG,
            'minutes_worked' => 480,
            'status' => 'closed',
        ]);

        Sanctum::actingAs($world['student']);

        $this->getJson('/api/student/dashboard')
            ->assertOk()
            ->assertJsonPath('progress.hours.hours_completed', 8)
            ->assertJsonPath('progress.hours.hours_required', 486)
            // 8 / 486 rounds to 2%.
            ->assertJsonPath('progress.hours.hours_percent', 2);
    }

    /**
     * The scan landing page must be able to say what the button will do
     * before the browser is asked for a location permission.
     */
    public function test_the_scan_preview_reports_the_next_action_without_coordinates(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['student']);

        $this->getJson('/api/student/dtr/scan?site_token='.$world['geofence']->token)
            ->assertOk()
            ->assertJsonPath('next_action', 'clock_in')
            ->assertJsonPath('site.label', 'Main Office')
            // WHOSE record this punch would land on. The QR opens in whichever
            // browser the phone treats as default, which on a shared handset
            // may hold someone else's session — and the scan looks identical
            // either way unless the page can name the account.
            ->assertJsonPath('student.name', $world['student']->name)
            ->assertJsonPath('student.username', $world['student']->username);

        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertOk();

        $this->getJson('/api/student/dtr/scan?site_token='.$world['geofence']->token)
            ->assertOk()
            ->assertJsonPath('next_action', 'clock_out');
    }

    /**
     * The whole point of storing coordinates: the supervisor reviewing a
     * period needs to see where each punch was taken, not just when.
     */
    public function test_a_punch_records_its_coordinates_accuracy_and_distance(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['student']);

        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            // ~100m north of the registered point, inside the 150m fence.
            'latitude' => self::SITE_LAT + 0.0009,
            'longitude' => self::SITE_LNG,
            'accuracy' => 35,
        ])->assertOk()->assertJsonPath('distance_meters', 100);

        $session = DtrSession::first();

        $this->assertSame(35, $session->time_in_accuracy);
        $this->assertSame(100, $session->time_in_distance);
        $this->assertEqualsWithDelta(self::SITE_LAT + 0.0009, $session->time_in_lat, 0.0000001);
    }

    /**
     * A completed student is READ-ONLY, not locked out.
     *
     * PROJECT.md's rule is explicit: write endpoints need an `active`
     * enrollment, read endpoints accept `active` OR `completed`. The DTR page
     * is a read surface, and their dashboard already shows the hours figure —
     * so 422ing the page that explains that figure is inconsistent.
     */
    public function test_a_completed_student_can_still_read_their_time_record_but_not_punch(): void
    {
        $world = $this->world();

        DtrSession::create([
            'student_id' => $world['student']->id,
            'batch_id' => $world['batch']->id,
            'geofence_id' => $world['geofence']->id,
            'work_date' => now()->toDateString(),
            'time_in' => now()->subHours(8),
            'time_in_lat' => self::SITE_LAT,
            'time_in_lng' => self::SITE_LNG,
            'time_out' => now(),
            'time_out_lat' => self::SITE_LAT,
            'time_out_lng' => self::SITE_LNG,
            'minutes_worked' => 480,
            'status' => 'closed',
        ]);

        BatchStudent::where('student_id', $world['student']->id)->first()->update(['status' => 'completed']);

        Sanctum::actingAs($world['student']);

        // Reading their finished record still works.
        $this->getJson('/api/student/dtr')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('progress.hours_completed', 8);

        // Writing does not — OJT is over.
        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertStatus(422);
    }

    /**
     * REGRESSION: the database must refuse a second open session even when the
     * service's check-then-write is beaten by a concurrent request.
     *
     * Verified to genuinely fail before dtr_sessions.open_session_key existed —
     * the DB accepted two open sessions for one student, leaving them unable to
     * clock out of either. Writing directly through the model here bypasses
     * DtrService on purpose: the point is that the invariant survives WITHOUT
     * the service's help, which is exactly the situation a race creates.
     */
    public function test_the_database_refuses_a_second_open_session_for_one_student(): void
    {
        $world = $this->world();

        $row = fn () => [
            'student_id' => $world['student']->id,
            'batch_id' => $world['batch']->id,
            'geofence_id' => $world['geofence']->id,
            'work_date' => now()->toDateString(),
            'time_in' => now(),
            'time_in_lat' => self::SITE_LAT,
            'time_in_lng' => self::SITE_LNG,
            'status' => 'open',
        ];

        DtrSession::create($row());

        $this->expectException(UniqueConstraintViolationException::class);
        DtrSession::create($row());
    }

    /**
     * Closing a session must free the student to clock in again — if the
     * unique key were left populated on close, a student's FIRST ever clock-out
     * would lock them out of the DTR permanently.
     */
    public function test_closing_a_session_releases_the_open_session_key(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['student']);

        $punch = fn () => $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ]);

        $punch()->assertOk()->assertJsonPath('action', 'clocked_in');
        $punch()->assertOk()->assertJsonPath('action', 'clocked_out');
        $punch()->assertOk()->assertJsonPath('action', 'clocked_in');

        $this->assertDatabaseCount('dtr_sessions', 2);
        $this->assertSame(
            1,
            DtrSession::where('student_id', $world['student']->id)->whereNotNull('open_session_key')->count(),
        );
    }

    /**
     * REGRESSION: a phone with no GPS lock indoors can report a six-figure
     * accuracy radius. A max: rule on that field would 422 the whole punch —
     * refusing a student standing exactly where they should be, over a
     * diagnostic value that gates nothing. It is clamped, not rejected.
     */
    public function test_an_absurd_accuracy_reading_is_clamped_rather_than_rejected(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['student']);

        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'accuracy' => 250000,
        ])->assertOk()->assertJsonPath('action', 'clocked_in');

        $this->assertSame(65535, DtrSession::first()->time_in_accuracy);
    }

    /**
     * The browser sends coords.accuracy as a float. It must not 422 the punch
     * on the integer rule.
     */
    public function test_a_fractional_accuracy_reading_is_accepted(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['student']);

        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'accuracy' => 18.7431,
        ])->assertOk();

        $this->assertSame(19, DtrSession::first()->time_in_accuracy);
    }

    /**
     * A shift that starts before midnight belongs to the day it STARTED, and
     * must not be mistaken for an implausible session.
     */
    public function test_a_shift_across_midnight_keeps_the_start_date_and_counts_normally(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['student']);

        Carbon::setTestNow(Carbon::parse('2026-08-20 22:00:00'));
        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-08-21 02:00:00'));
        $this->postJson('/api/student/dtr/punch', [
            'site_token' => $world['geofence']->token,
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertOk()->assertJsonPath('action', 'clocked_out');

        Carbon::setTestNow();

        // Asserted through the model rather than assertDatabaseHas with a bare
        // date string: SQLite keeps the "00:00:00" that MySQL truncates off a
        // date column, so a raw comparison fails on a perfectly correct row.
        // The same trap is why every query in this feature uses whereDate().
        $session = DtrSession::first();

        $this->assertSame('2026-08-20', $session->work_date->toDateString());
        $this->assertSame('closed', $session->status);
        $this->assertSame(240, $session->minutes_worked);
    }
}
