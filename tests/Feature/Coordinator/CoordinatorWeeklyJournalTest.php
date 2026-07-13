<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\User;
use App\Models\WeeklyLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoordinatorWeeklyJournalTest extends TestCase
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

    private function batchFor(Program $program, User $coordinator): Batch
    {
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

    private function enroll(Batch $batch): BatchStudent
    {
        $student = User::factory()->create(['role' => 'student']);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company = Company::create(['name' => 'Co '.uniqid(), 'address' => 'Addr', 'is_active' => true]);

        return BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);
    }

    private function log(
        BatchStudent $enrollment,
        string $status = 'pending',
        bool $submitted = true,
        string $weekStart = '2026-06-29'
    ): WeeklyLog {
        $start = \Carbon\Carbon::parse($weekStart);

        return WeeklyLog::create([
            'batch_id' => $enrollment->batch_id,
            'student_id' => $enrollment->student_id,
            'week_start' => $start->toDateString(),
            'week_end' => $start->copy()->addDays(4)->toDateString(),
            'status' => $status,
            'narrative' => "MONDAY\nDid onboarding.\n\nTUESDAY\nWrote reports.",
            'submitted_at' => $submitted ? now() : null,
        ]);
    }

    public function test_index_lists_submitted_logs_of_in_scope_students_only(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $inScope = $this->enroll($batch);
        $submitted = $this->log($inScope);
        // A never-submitted draft must not appear.
        $draftEnrollment = $this->enroll($batch);
        $this->log($draftEnrollment, submitted: false, weekStart: '2026-07-06');

        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoord = $this->coordinatorFor($bsba);
        $otherBatch = $this->batchFor($bsba, $otherCoord);
        $this->log($this->enroll($otherBatch));

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/weekly-journals');

        $response->assertOk();
        $rows = collect($response->json('logs.data'));
        $this->assertCount(1, $rows);
        $this->assertSame($submitted->id, $rows->first()['id']);
        $this->assertSame($inScope->student_id, $rows->first()['student_id']);
        $this->assertSame('BSIT', $rows->first()['program']);
        $this->assertSame('pending', $rows->first()['status']);
        $this->assertSame('2026-06-29', $rows->first()['week_start']);
        $this->assertSame('2026-07-03', $rows->first()['week_end']);

        // Full-scope programs list powers the filter dropdown.
        $this->assertTrue(collect($response->json('programs'))->pluck('id')->contains($bsit->id));
    }

    public function test_index_filters_by_status_program_and_week_range(): void
    {
        $bsa = $this->programFor('BSA', 'CABM-B');
        $bsbaFm = $this->programFor('BSBA-FM', 'CABM-B');
        $coordinator = $this->coordinatorFor($bsa);

        $bsaBatch = $this->batchFor($bsa, $coordinator);
        $approved = $this->log($this->enroll($bsaBatch), status: 'approved');
        $pending = $this->log($this->enroll($bsaBatch), status: 'pending', weekStart: '2026-07-06');

        $bsbaBatch = $this->batchFor($bsbaFm, $coordinator);
        $otherProgramLog = $this->log($this->enroll($bsbaBatch), weekStart: '2026-07-06');

        Sanctum::actingAs($coordinator, ['*']);

        $byStatus = $this->getJson('/api/coordinator/weekly-journals?status=approved');
        $byStatus->assertOk();
        $this->assertSame([$approved->id], collect($byStatus->json('logs.data'))->pluck('id')->all());

        $byProgram = $this->getJson("/api/coordinator/weekly-journals?program_id={$bsa->id}");
        $byProgram->assertOk();
        $ids = collect($byProgram->json('logs.data'))->pluck('id');
        $this->assertTrue($ids->contains($approved->id) && $ids->contains($pending->id));
        $this->assertFalse($ids->contains($otherProgramLog->id));

        $byRange = $this->getJson('/api/coordinator/weekly-journals?from=2026-07-06&to=2026-07-10');
        $byRange->assertOk();
        $rangeIds = collect($byRange->json('logs.data'))->pluck('id');
        $this->assertFalse($rangeIds->contains($approved->id));
        $this->assertTrue($rangeIds->contains($pending->id) && $rangeIds->contains($otherProgramLog->id));
    }

    public function test_index_rejects_out_of_scope_program_filter(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $outside = $this->programFor('BSBA-FM', 'CABM-B');

        Sanctum::actingAs($coordinator, ['*']);

        $this->getJson("/api/coordinator/weekly-journals?program_id={$outside->id}")->assertForbidden();
    }

    public function test_show_matches_supervisor_payload_shape_for_in_scope_log(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        $enrollment = $this->enroll($batch);
        $weeklyLog = $this->log($enrollment, status: 'returned');
        $weeklyLog->update(['supervisor_comment' => 'Add more detail.']);

        JournalEntry::create([
            'student_id' => $enrollment->student_id,
            'batch_id' => $batch->id,
            'entry_date' => '2026-06-29',
            'content' => ['daily_accomplishment' => 'Did onboarding.'],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson("/api/coordinator/weekly-journals/{$weeklyLog->id}");

        $response->assertOk();
        $response->assertJsonStructure([
            'id', 'student' => ['id', 'name', 'student_id_number'],
            'week_start', 'week_end', 'status', 'supervisor_comment',
            'narrative', 'submitted_at', 'reviewed_at', 'daily_entries',
        ]);
        $response->assertJsonPath('id', $weeklyLog->id);
        $response->assertJsonPath('student.id', $enrollment->student_id);
        $response->assertJsonPath('status', 'returned');
        $response->assertJsonPath('supervisor_comment', 'Add more detail.');
        $response->assertJsonPath('narrative', "MONDAY\nDid onboarding.\n\nTUESDAY\nWrote reports.");
        $this->assertCount(1, $response->json('daily_entries'));
    }

    public function test_show_is_forbidden_for_out_of_scope_log(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $this->batchFor($bsit, $coordinator);

        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoord = $this->coordinatorFor($bsba);
        $outsideLog = $this->log($this->enroll($this->batchFor($bsba, $otherCoord)));

        Sanctum::actingAs($coordinator, ['*']);

        $this->getJson("/api/coordinator/weekly-journals/{$outsideLog->id}")->assertForbidden();
    }

    public function test_pdf_downloads_for_in_scope_log_and_403s_out_of_scope(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);
        $weeklyLog = $this->log($this->enroll($batch));

        $bsba = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoord = $this->coordinatorFor($bsba);
        $outsideLog = $this->log($this->enroll($this->batchFor($bsba, $otherCoord)));

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->get("/api/coordinator/weekly-journals/{$weeklyLog->id}/pdf");
        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $this->get("/api/coordinator/weekly-journals/{$outsideLog->id}/pdf")->assertForbidden();
    }

    public function test_other_roles_cannot_access_coordinator_weekly_journals(): void
    {
        $bsit = $this->programFor('BSIT', 'CAST');
        $coordinator = $this->coordinatorFor($bsit);
        $weeklyLog = $this->log($this->enroll($this->batchFor($bsit, $coordinator)));

        Sanctum::actingAs(User::factory()->create(['role' => 'student']), ['*']);
        $this->getJson('/api/coordinator/weekly-journals')->assertForbidden();
        $this->getJson("/api/coordinator/weekly-journals/{$weeklyLog->id}")->assertForbidden();
    }
}
