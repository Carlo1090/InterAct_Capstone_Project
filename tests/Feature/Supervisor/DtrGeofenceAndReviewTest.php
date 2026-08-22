<?php

namespace Tests\Feature\Supervisor;

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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DtrGeofenceAndReviewTest extends TestCase
{
    use RefreshDatabase;

    private const SITE_LAT = 9.6496;

    private const SITE_LNG = 124.1264;

    /**
     * @return array{supervisor: User, company: Company, student: User, batch: Batch}
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

        return compact('supervisor', 'company', 'student', 'batch');
    }

    public function test_a_supervisor_creates_a_geofence_from_their_captured_location(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['supervisor']);

        $this->postJson('/api/supervisor/dtr/geofences', [
            'company_id' => $world['company']->id,
            'label' => 'Main Office',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'captured_accuracy' => 15,
        ])
            ->assertCreated()
            ->assertJsonPath('geofence.label', 'Main Office')
            // The documented 150m default, deliberately generous because GPS
            // indoors is poor.
            ->assertJsonPath('geofence.radius_meters', 150)
            ->assertJsonPath('geofence.accuracy_is_poor', false);

        $this->assertDatabaseHas('company_geofences', [
            'company_id' => $world['company']->id,
            'label' => 'Main Office',
            'is_active' => true,
        ]);
    }

    /**
     * A fence anchored on a vague fix strands every intern outside it. The
     * supervisor is warned rather than blocked, so the flag has to reach them.
     */
    public function test_a_poor_accuracy_capture_is_flagged_back_to_the_supervisor(): void
    {
        $world = $this->world();
        Sanctum::actingAs($world['supervisor']);

        $this->postJson('/api/supervisor/dtr/geofences', [
            'company_id' => $world['company']->id,
            'label' => 'Main Office',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'captured_accuracy' => 850,
        ])
            ->assertCreated()
            ->assertJsonPath('geofence.accuracy_is_poor', true);
    }

    public function test_a_supervisor_cannot_create_a_site_for_a_company_they_do_not_represent(): void
    {
        $world = $this->world();
        $other = Company::create(['name' => 'Other Inc', 'address' => 'Elsewhere', 'is_active' => true]);

        Sanctum::actingAs($world['supervisor']);

        $this->postJson('/api/supervisor/dtr/geofences', [
            'company_id' => $other->id,
            'label' => 'Not Mine',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
        ])->assertStatus(403);
    }

    public function test_the_qr_endpoint_returns_a_printable_svg_pointing_at_the_spa_scan_page(): void
    {
        $world = $this->world();
        $geofence = CompanyGeofence::create([
            'company_id' => $world['company']->id,
            'label' => 'Main Office',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'radius_meters' => 150,
            'is_active' => true,
        ]);

        Sanctum::actingAs($world['supervisor']);

        $response = $this->get("/api/supervisor/dtr/geofences/{$geofence->id}/qr");

        $response->assertOk();
        $this->assertStringContainsString('image/svg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('<svg', $response->getContent());
    }

    /**
     * Coordinates are write-once. Allowing them to be edited would let a
     * supervisor quietly move a fence to their house with none of the
     * capture evidence refreshed.
     */
    public function test_updating_a_site_cannot_move_its_coordinates(): void
    {
        $world = $this->world();
        $geofence = CompanyGeofence::create([
            'company_id' => $world['company']->id,
            'label' => 'Main Office',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'radius_meters' => 150,
            'is_active' => true,
        ]);

        Sanctum::actingAs($world['supervisor']);

        $this->putJson("/api/supervisor/dtr/geofences/{$geofence->id}", [
            'label' => 'Renamed',
            'radius_meters' => 200,
            'latitude' => 1.0,
            'longitude' => 1.0,
        ])->assertOk();

        $geofence->refresh();

        $this->assertSame('Renamed', $geofence->label);
        $this->assertSame(200, $geofence->radius_meters);
        $this->assertEqualsWithDelta(self::SITE_LAT, $geofence->latitude, 0.0000001);
        $this->assertEqualsWithDelta(self::SITE_LNG, $geofence->longitude, 0.0000001);
    }

    /**
     * Retiring a site must not delete it — dtr_sessions reference it for
     * their audit trail.
     */
    public function test_retiring_a_site_deactivates_rather_than_deletes_it(): void
    {
        $world = $this->world();
        $geofence = CompanyGeofence::create([
            'company_id' => $world['company']->id,
            'label' => 'Main Office',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'radius_meters' => 150,
            'is_active' => true,
        ]);

        Sanctum::actingAs($world['supervisor']);

        $this->deleteJson("/api/supervisor/dtr/geofences/{$geofence->id}")->assertOk();

        $this->assertDatabaseHas('company_geofences', [
            'id' => $geofence->id,
            'is_active' => false,
        ]);
    }

    private function openSession(array $world): DtrSession
    {
        $geofence = CompanyGeofence::create([
            'company_id' => $world['company']->id,
            'label' => 'Main Office',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'radius_meters' => 150,
            'is_active' => true,
        ]);

        return DtrSession::create([
            'student_id' => $world['student']->id,
            'batch_id' => $world['batch']->id,
            'geofence_id' => $geofence->id,
            'work_date' => now()->subDay()->toDateString(),
            'time_in' => now()->subDay()->setTime(8, 0),
            'time_in_lat' => self::SITE_LAT,
            'time_in_lng' => self::SITE_LNG,
            'status' => 'open',
        ]);
    }

    /**
     * The forgotten-clock-out fix, and the reason it exists: the session was
     * banking nothing while open, and becomes real hours once corrected.
     */
    public function test_adjusting_a_forgotten_clock_out_closes_it_and_banks_the_hours(): void
    {
        $world = $this->world();
        $session = $this->openSession($world);
        $dtr = app(DtrService::class);

        $this->assertSame(0, $dtr->minutesCompleted($world['student']->id, $world['batch']->id));

        Sanctum::actingAs($world['supervisor']);

        $this->postJson("/api/supervisor/dtr/sessions/{$session->id}/adjust", [
            'minutes_worked' => 480,
            'reason' => 'Intern left at 5pm but forgot to scan out.',
        ])->assertOk()->assertJsonPath('session.status', 'closed');

        $this->assertSame(480, $dtr->minutesCompleted($world['student']->id, $world['batch']->id));

        $this->assertDatabaseHas('dtr_sessions', [
            'id' => $session->id,
            'minutes_worked' => 480,
            'adjusted_by' => $world['supervisor']->id,
            'adjustment_reason' => 'Intern left at 5pm but forgot to scan out.',
        ]);
    }

    public function test_an_adjustment_without_a_reason_is_rejected(): void
    {
        $world = $this->world();
        $session = $this->openSession($world);

        Sanctum::actingAs($world['supervisor']);

        $this->postJson("/api/supervisor/dtr/sessions/{$session->id}/adjust", [
            'minutes_worked' => 480,
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    /**
     * Voiding discounts the hours but keeps the row — a discarded punch that
     * left no trace would be indistinguishable from one that never happened.
     */
    public function test_voiding_a_session_stops_it_counting_but_keeps_the_record(): void
    {
        $world = $this->world();
        $session = $this->openSession($world);
        $session->update(['minutes_worked' => 480, 'status' => 'closed', 'time_out' => now()->subDay()->setTime(16, 0)]);

        $dtr = app(DtrService::class);
        $this->assertSame(480, $dtr->minutesCompleted($world['student']->id, $world['batch']->id));

        Sanctum::actingAs($world['supervisor']);

        $this->postJson("/api/supervisor/dtr/sessions/{$session->id}/void", [
            'reason' => 'Duplicate scan; the intern was not on site.',
        ])->assertOk()->assertJsonPath('session.status', 'void');

        $this->assertSame(0, $dtr->minutesCompleted($world['student']->id, $world['batch']->id));
        $this->assertDatabaseHas('dtr_sessions', ['id' => $session->id, 'status' => 'void']);
    }

    /**
     * The practical recovery path, and the reason it must work: a student with
     * a stuck open session cannot clock in anywhere (the one-open-session rule
     * is now a database constraint). If closing it by hand did not release
     * them, a single forgotten scan would end their DTR for the rest of the
     * placement.
     */
    public function test_adjusting_an_open_session_frees_the_student_to_clock_in_again(): void
    {
        $world = $this->world();
        $session = $this->openSession($world);

        Sanctum::actingAs($world['supervisor']);

        $this->postJson("/api/supervisor/dtr/sessions/{$session->id}/adjust", [
            'minutes_worked' => 480,
            'reason' => 'Forgot to scan out.',
        ])->assertOk();

        $this->assertDatabaseHas('dtr_sessions', ['id' => $session->id, 'open_session_key' => null]);
        $this->assertNull(app(DtrService::class)->openSessionFor($world['student']));
    }

    /** Voiding a stuck session must release the student the same way. */
    public function test_voiding_an_open_session_frees_the_student_to_clock_in_again(): void
    {
        $world = $this->world();
        $session = $this->openSession($world);

        Sanctum::actingAs($world['supervisor']);

        $this->postJson("/api/supervisor/dtr/sessions/{$session->id}/void", [
            'reason' => 'Scanned by mistake.',
        ])->assertOk();

        $this->assertDatabaseHas('dtr_sessions', ['id' => $session->id, 'open_session_key' => null]);
        $this->assertNull(app(DtrService::class)->openSessionFor($world['student']));
    }

    public function test_a_supervisor_cannot_adjust_another_companys_intern(): void
    {
        $world = $this->world();
        $session = $this->openSession($world);

        $stranger = User::factory()->create(['role' => 'supervisor']);
        $strangerCompany = Company::create(['name' => 'Other Inc', 'address' => 'Elsewhere', 'is_active' => true]);
        CompanySupervisor::create([
            'company_id' => $strangerCompany->id,
            'user_id' => $stranger->id,
            'position' => 'Supervisor',
        ]);

        Sanctum::actingAs($stranger);

        $this->postJson("/api/supervisor/dtr/sessions/{$session->id}/adjust", [
            'minutes_worked' => 480,
            'reason' => 'Not my intern.',
        ])->assertStatus(403);
    }

    /**
     * The review queue defaults to what needs a human: sessions left open
     * from a previous day, and flagged ones. A session opened today is
     * simply someone currently at work.
     */
    public function test_the_review_queue_defaults_to_sessions_needing_attention(): void
    {
        $world = $this->world();
        $stale = $this->openSession($world); // opened yesterday, still open

        // A SECOND intern for today's in-progress session. It cannot be the
        // same student: dtr_sessions.open_session_key now enforces one open
        // session per student at the database level, so the two-open-sessions
        // shape this test originally built is no longer reachable — which is
        // the point of that index.
        $today = User::factory()->create(['role' => 'student']);
        BatchStudent::create([
            'batch_id' => $world['batch']->id,
            'student_id' => $today->id,
            'company_id' => $world['company']->id,
            'supervisor_id' => $world['supervisor']->id,
            'status' => 'active',
        ]);

        DtrSession::create([
            'student_id' => $today->id,
            'batch_id' => $world['batch']->id,
            'geofence_id' => $stale->geofence_id,
            'work_date' => now()->toDateString(),
            'time_in' => now()->subHour(),
            'time_in_lat' => self::SITE_LAT,
            'time_in_lng' => self::SITE_LNG,
            'status' => 'open',
        ]);

        Sanctum::actingAs($world['supervisor']);

        $response = $this->getJson('/api/supervisor/dtr/sessions')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($stale->id, $response->json('data.0.id'));
    }
}
