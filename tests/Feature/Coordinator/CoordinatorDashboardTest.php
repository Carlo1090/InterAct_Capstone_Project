<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Missing days are DERIVED (a working day with no submitted entry), never read
 * off journal_entries.status — that column only stores draft/submitted, which
 * is why Students Behind used to be silently zero.
 *
 * The clock is frozen so "this week" is a known span: Thursday 2026-10-08,
 * whose week runs Monday 10-05 through today — four working days.
 */
class CoordinatorDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const THURSDAY = '2026-10-08 10:00:00';

    private const MONDAY = '2026-10-05';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::THURSDAY));
    }

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

    /**
     * @param  array{0: int, 1: int}  $workingDays  ISO weekday range [start, end]
     */
    private function batchFor(Program $program, User $coordinator, string $startDate = '2026-09-01', array $workingDays = [1, 5]): Batch
    {
        return Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Batch '.uniqid(),
            'start_date' => $startDate,
            'end_date' => '2026-12-31',
            'required_hours' => 486,
            'working_days_start' => $workingDays[0],
            'working_days_end' => $workingDays[1],
            'daily_reminder_time' => '21:00:00',
            'academic_year' => '2026',
            'semester' => 'Internship',
            'is_active' => true,
        ]);
    }

    private function enroll(Batch $batch, ?string $name = null, string $status = 'active'): BatchStudent
    {
        $student = User::factory()->create(['role' => 'student', ...($name ? ['name' => $name] : [])]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company = Company::create(['name' => 'Co '.uniqid(), 'address' => 'Addr', 'is_active' => true]);

        return BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => $status,
        ]);
    }

    /**
     * @param  list<string>  $dates
     */
    private function entries(BatchStudent $enrollment, string $status, array $dates): void
    {
        foreach ($dates as $date) {
            JournalEntry::create([
                'student_id' => $enrollment->student_id,
                'batch_id' => $enrollment->batch_id,
                'entry_date' => $date,
                'content' => ['daily_accomplishment' => 'x'],
                'status' => $status,
            ]);
        }
    }

    private function dashboardFor(User $coordinator): TestResponse
    {
        Sanctum::actingAs($coordinator, ['*']);

        return $this->getJson('/api/coordinator/dashboard')->assertOk();
    }

    /** @return array<int, int> student_id => missing_count */
    private function missingByStudent(TestResponse $response): array
    {
        return collect($response->json('students_behind'))->pluck('missing_count', 'student_id')->all();
    }

    public function test_missing_days_are_derived_from_working_days_without_a_submitted_entry(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $onTrack = $this->enroll($batch);
        $behind = $this->enroll($batch);

        $this->entries($onTrack, 'submitted', ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08']);
        $this->entries($behind, 'submitted', ['2026-10-05']);

        $response = $this->dashboardFor($coordinator);

        $response->assertJsonPath('stats.active_interns', 2);
        $response->assertJsonPath('stats.journals_submitted_this_week', 5);
        $response->assertJsonPath('stats.journals_missing_this_week', 3);
        $response->assertJsonPath('stats.active_batches', 1);
        $response->assertJsonPath('stats.students_behind', 1);
        $response->assertJsonPath('week.start', self::MONDAY);
        $response->assertJsonPath('week.end', '2026-10-08');

        $row = $response->json('students_behind.0');
        $this->assertSame($behind->student_id, $row['student_id']);
        $this->assertSame(3, $row['missing_count']);
        $this->assertSame('BSIT', $row['program']);
        $this->assertSame($behind->company->name, $row['company']);
    }

    public function test_a_batch_that_starts_mid_week_counts_only_from_its_start(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator, startDate: '2026-10-07'); // Wednesday

        $enrollment = $this->enroll($batch);

        $response = $this->dashboardFor($coordinator);

        // Wednesday and Thursday only — Monday and Tuesday predate the batch.
        $this->assertSame([$enrollment->student_id => 2], $this->missingByStudent($response));
        $response->assertJsonPath('stats.journals_missing_this_week', 2);
    }

    public function test_a_batch_that_has_not_started_owes_nothing(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $this->enroll($this->batchFor($bsit, $coordinator, startDate: '2026-10-12'));

        $response = $this->dashboardFor($coordinator);

        $response->assertJsonPath('stats.active_interns', 1);
        $response->assertJsonPath('stats.students_behind', 0);
        $response->assertJsonPath('students_behind', []);
    }

    public function test_weekends_do_not_count_as_missing(): void
    {
        // The only point in this file where the clock moves off a weekday: a
        // weekend can only fall inside Monday-to-today when today IS one.
        $this->travelTo(Carbon::parse('2026-10-11 10:00:00')); // Sunday

        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);

        $weekdays = $this->enroll($this->batchFor($bsit, $coordinator));
        $everyDay = $this->enroll($this->batchFor($bsit, $coordinator, workingDays: [1, 7]));

        $response = $this->dashboardFor($coordinator);

        // Same seven calendar days; only the batch that works weekends owes them.
        $this->assertSame(5, $this->missingByStudent($response)[$weekdays->student_id]);
        $this->assertSame(7, $this->missingByStudent($response)[$everyDay->student_id]);
        $response->assertJsonPath('stats.journals_missing_this_week', 12);
    }

    public function test_a_draft_does_not_count_as_submitted(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $enrollment = $this->enroll($this->batchFor($bsit, $coordinator));

        $this->entries($enrollment, 'draft', ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08']);

        $response = $this->dashboardFor($coordinator);

        $this->assertSame([$enrollment->student_id => 4], $this->missingByStudent($response));
        $response->assertJsonPath('stats.journals_submitted_this_week', 0);
    }

    public function test_completed_enrollments_are_excluded(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $active = $this->enroll($batch);
        $this->enroll($batch, status: 'completed');

        $response = $this->dashboardFor($coordinator);

        $response->assertJsonPath('stats.active_interns', 1);
        $this->assertSame([$active->student_id => 4], $this->missingByStudent($response));
        $response->assertJsonPath('stats.journals_missing_this_week', 4);
    }

    public function test_students_behind_are_sorted_by_missing_count_then_name(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $zed = $this->enroll($batch, 'Zed Uy');
        $this->enroll($batch, 'Bea Lim');
        $this->enroll($batch, 'Ana Cruz');

        $this->entries($zed, 'submitted', ['2026-10-05', '2026-10-06']); // 2 missing

        $response = $this->dashboardFor($coordinator);

        $this->assertSame(
            [['Ana Cruz', 4], ['Bea Lim', 4], ['Zed Uy', 2]],
            collect($response->json('students_behind'))->map(fn ($row) => [$row['name'], $row['missing_count']])->all(),
        );
    }

    public function test_dashboard_excludes_out_of_scope_data(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $this->batchFor($bsit, $coordinator);

        // Another department's batch + an intern who has submitted nothing.
        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherEnrollment = $this->enroll($this->batchFor($bsba, $this->coordinatorFor($bsba)));
        $this->entries($otherEnrollment, 'submitted', ['2026-10-05']);

        $response = $this->dashboardFor($coordinator);

        $response->assertJsonPath('stats.active_interns', 0);
        $response->assertJsonPath('stats.journals_submitted_this_week', 0);
        $response->assertJsonPath('stats.journals_missing_this_week', 0);
        $response->assertJsonPath('stats.students_behind', 0);
        $response->assertJsonPath('stats.active_batches', 1);
    }
}
