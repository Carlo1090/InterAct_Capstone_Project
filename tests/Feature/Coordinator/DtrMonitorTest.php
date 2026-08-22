<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanyGeofence;
use App\Models\Department;
use App\Models\DtrSession;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DtrMonitorTest extends TestCase
{
    use RefreshDatabase;

    private const SITE_LAT = 9.6496;

    private const SITE_LNG = 124.1264;

    private Department $department;

    private Program $program;

    private User $coordinator;

    private Batch $batch;

    private Company $company;

    private CompanyGeofence $geofence;

    private int $supervisorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::create(['name' => 'CAST', 'code' => 'CAST', 'is_active' => true]);
        $this->program = Program::create([
            'department_id' => $this->department->id,
            'name' => 'BS Information Technology',
            'code' => 'BSIT',
            'is_active' => true,
        ]);

        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'dtr_enabled' => true]);
        $this->coordinator->departmentsCoordinated()->attach($this->department->id);

        $this->batch = Batch::create([
            'program_id' => $this->program->id,
            'coordinator_id' => $this->coordinator->id,
            'name' => 'Batch 2026',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(3),
            'required_hours' => 100,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026-2027',
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $this->company = Company::create(['name' => 'Acme Corp', 'address' => 'Tagbilaran', 'is_active' => true]);

        $this->geofence = CompanyGeofence::create([
            'company_id' => $this->company->id,
            'label' => 'Main Office',
            'latitude' => self::SITE_LAT,
            'longitude' => self::SITE_LNG,
            'radius_meters' => 150,
            'is_active' => true,
        ]);

        $this->supervisorId = $supervisor->id;
    }

    private function intern(string $name): User
    {
        $student = User::factory()->create([
            'role' => 'student',
            'name' => $name,
            'program_id' => $this->program->id,
        ]);

        BatchStudent::create([
            'batch_id' => $this->batch->id,
            'student_id' => $student->id,
            'company_id' => $this->company->id,
            'supervisor_id' => $this->supervisorId,
            'status' => 'active',
        ]);

        StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $this->batch->id,
            'submission_status' => 'approved',
            'personal_info' => [],
            'academic_info' => [],
            'ojt_info' => [],
        ]);

        return $student;
    }

    private function punchRow(User $student, string $status, ?int $minutes): DtrSession
    {
        return DtrSession::create([
            'student_id' => $student->id,
            'batch_id' => $this->batch->id,
            'geofence_id' => $this->geofence->id,
            'work_date' => now()->toDateString(),
            'time_in' => now()->subHours(8),
            'time_in_lat' => self::SITE_LAT,
            'time_in_lng' => self::SITE_LNG,
            'minutes_worked' => $minutes,
            'status' => $status,
        ]);
    }

    /**
     * The tallies come from ONE grouped query rather than three per intern.
     * This asserts the grouping actually agrees with the per-status rules:
     * only 'closed' minutes count, while open and flagged are surfaced as
     * counts needing attention.
     */
    public function test_hours_and_attention_counts_are_tallied_correctly(): void
    {
        $alice = $this->intern('Alice');
        $bob = $this->intern('Bob');

        // Alice: 2 counted sessions (600 min = 10h), 1 flagged, 1 voided.
        $this->punchRow($alice, 'closed', 300);
        $this->punchRow($alice, 'closed', 300);
        $this->punchRow($alice, 'flagged', 1200);
        $this->punchRow($alice, 'void', 480);

        // Bob: one session still open, nothing banked.
        $this->punchRow($bob, 'open', null);

        Sanctum::actingAs($this->coordinator);

        $response = $this->getJson('/api/coordinator/dtr')->assertOk();

        $rows = collect($response->json('rows'))->keyBy('student_name');

        $this->assertEqualsWithDelta(10, $rows['Alice']['hours_completed'], 0.01);
        $this->assertSame(100, $rows['Alice']['hours_required']);
        $this->assertSame(10, $rows['Alice']['hours_percent']);
        $this->assertSame(1, $rows['Alice']['flagged_sessions']);
        $this->assertSame(0, $rows['Alice']['open_sessions']);

        // Flagged and voided minutes are deliberately excluded from the total.
        $this->assertNotEquals(28, $rows['Alice']['hours_completed']);

        $this->assertEqualsWithDelta(0, $rows['Bob']['hours_completed'], 0.01);
        $this->assertSame(1, $rows['Bob']['open_sessions']);
    }

    /**
     * REGRESSION: the tallies were originally three queries PER intern, which
     * is 300 round trips for a 100-intern department on an instance that cold
     * starts. They are now one grouped query for the whole page.
     */
    public function test_the_monitor_does_not_issue_queries_per_intern(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $name) {
            $this->punchRow($this->intern($name), 'closed', 120);
        }

        Sanctum::actingAs($this->coordinator);

        DB::enableQueryLog();
        $this->getJson('/api/coordinator/dtr')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Comfortably above what the page needs, far below the ~20 the old
        // per-intern version would have used for six interns.
        $this->assertLessThan(
            15,
            $count,
            "The monitor issued {$count} queries for 6 interns — the per-intern N+1 is back."
        );
    }

    /** A coordinator observes; corrections belong to the supervisor. */
    public function test_the_monitor_exposes_no_write_actions(): void
    {
        $student = $this->intern('Alice');
        $session = $this->punchRow($student, 'open', null);

        Sanctum::actingAs($this->coordinator);

        $this->postJson("/api/coordinator/dtr/sessions/{$session->id}/adjust", [
            'minutes_worked' => 480,
            'reason' => 'Nope.',
        ])->assertNotFound();
    }

    /**
     * The coordinator's real check: a supervisor who generated the QR code
     * away from the workplace anchored the fence somewhere wrong, and nothing
     * automated can detect it.
     */
    public function test_the_sites_tab_exposes_coordinates_against_the_company_address(): void
    {
        $this->intern('Alice');

        Sanctum::actingAs($this->coordinator);

        $this->getJson('/api/coordinator/dtr/sites')
            ->assertOk()
            ->assertJsonPath('sites.0.label', 'Main Office')
            ->assertJsonPath('sites.0.company_address', 'Tagbilaran')
            ->assertJsonPath('sites.0.latitude', self::SITE_LAT)
            ->assertJsonPath('sites.0.map_url', 'https://www.google.com/maps/search/?api=1&query='.self::SITE_LAT.','.self::SITE_LNG);
    }

    /** Interns on a DTR-off coordinator's batch have no hours to report. */
    public function test_a_dtr_disabled_batch_contributes_no_rows(): void
    {
        $this->intern('Alice');
        $this->coordinator->update(['dtr_enabled' => false]);

        Sanctum::actingAs($this->coordinator);

        $this->getJson('/api/coordinator/dtr')
            ->assertOk()
            ->assertJsonCount(0, 'rows');
    }
}
