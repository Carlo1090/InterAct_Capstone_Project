<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use App\Models\WeeklyActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The coordinator's read-only list of Weekly Activity Log and Time Log
 * Summaries — the same posture as the info-sheet queue: scoped by the batch's
 * program, openable, downloadable, and never editable.
 */
class CoordinatorWeeklyActivityLogTest extends TestCase
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

    private function enroll(Batch $batch, string $name = 'Juan Dela Cruz'): BatchStudent
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name]);
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

    private function sheetFor(BatchStudent $enrollment, string $weekStart = '2026-06-29'): WeeklyActivityLog
    {
        $log = WeeklyActivityLog::create([
            'student_id' => $enrollment->student_id,
            'batch_id' => $enrollment->batch_id,
            'week_start' => $weekStart,
            'week_end' => date('Y-m-d', strtotime($weekStart.' +6 days')),
            'area_assigned' => 'Accounting Office',
            'no_of_hours' => 40,
        ]);

        $log->entries()->create([
            'inclusive_date_start' => $weekStart,
            'inclusive_date_end' => $weekStart,
            'activities' => 'Filed the monthly receipts.',
            'documents_records' => 'Official receipts',
            'objectives' => 'Apply bookkeeping procedures.',
            'supervisor_name' => 'Ms. Carmela Uy',
            'supervisor_position' => 'Accounting Head',
            'sort_order' => 1,
        ]);

        return $log;
    }

    public function test_coordinator_sees_only_in_scope_log_sheets(): void
    {
        $mine = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($mine);
        $ownSheet = $this->sheetFor($this->enroll($this->batchFor($mine, $coordinator), 'In Scope Student'));

        // Another department entirely, with its own coordinator.
        $theirs = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoordinator = $this->coordinatorFor($theirs);
        $this->sheetFor($this->enroll($this->batchFor($theirs, $otherCoordinator), 'Out Of Scope Student'));

        Sanctum::actingAs($coordinator, ['*']);
        $response = $this->getJson('/api/coordinator/weekly-activity-logs');

        $response->assertOk();
        $response->assertJsonCount(1, 'logs.data');
        $response->assertJsonPath('logs.data.0.id', $ownSheet->id);
        $response->assertJsonPath('logs.data.0.student_name', 'In Scope Student');
        $response->assertJsonPath('logs.data.0.entries_count', 1);
    }

    public function test_coordinator_can_read_and_download_an_in_scope_sheet(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $sheet = $this->sheetFor($this->enroll($this->batchFor($program, $coordinator)));

        Sanctum::actingAs($coordinator, ['*']);

        $show = $this->getJson("/api/coordinator/weekly-activity-logs/{$sheet->id}");
        $show->assertOk();
        $show->assertJsonPath('header.student_name', 'Juan Dela Cruz');
        $show->assertJsonPath('header.faculty_adviser', $coordinator->name);
        $show->assertJsonPath('entries.0.activities', 'Filed the monthly receipts.');

        $pdf = $this->get("/api/coordinator/weekly-activity-logs/{$sheet->id}/pdf");
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));

        // Same measured facsimile the student downloads — one shared renderer.
        $this->assertStringContainsString('MediaBox [0.000 0.000 612.000 792.000]', $pdf->getContent());
    }

    public function test_coordinator_cannot_reach_an_out_of_scope_sheet(): void
    {
        $mine = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($mine);

        $theirs = $this->programFor('BSBA-FM', 'CABM-B');
        $otherCoordinator = $this->coordinatorFor($theirs);
        $sheet = $this->sheetFor($this->enroll($this->batchFor($theirs, $otherCoordinator)));

        Sanctum::actingAs($coordinator, ['*']);

        $this->getJson("/api/coordinator/weekly-activity-logs/{$sheet->id}")->assertStatus(403);
        $this->getJson("/api/coordinator/weekly-activity-logs/{$sheet->id}/pdf")->assertStatus(403);
    }

    public function test_the_program_filter_rejects_a_program_outside_the_scope(): void
    {
        $mine = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($mine);
        $theirs = $this->programFor('BSBA-FM', 'CABM-B');

        Sanctum::actingAs($coordinator, ['*']);

        $this->getJson('/api/coordinator/weekly-activity-logs?program_id='.$theirs->id)->assertStatus(403);
    }

    public function test_the_search_filter_matches_a_student_by_name(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $batch = $this->batchFor($program, $coordinator);

        $this->sheetFor($this->enroll($batch, 'Maria Santos'));
        $this->sheetFor($this->enroll($batch, 'Pedro Reyes'));

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->getJson('/api/coordinator/weekly-activity-logs?search=Maria');
        $response->assertOk();
        $response->assertJsonCount(1, 'logs.data');
        $response->assertJsonPath('logs.data.0.student_name', 'Maria Santos');
    }

    /**
     * This surface is deliberately read-only — there is no approval step on
     * this form, and the paper copy is signed by the supervisor. Only the
     * student's own routes may write.
     */
    public function test_a_coordinator_cannot_edit_a_students_sheet(): void
    {
        $program = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($program);
        $sheet = $this->sheetFor($this->enroll($this->batchFor($program, $coordinator)));

        Sanctum::actingAs($coordinator, ['*']);

        // The student's own write endpoints are behind role:student.
        $this->putJson("/api/student/weekly-activity-logs/{$sheet->id}", ['area_assigned' => 'Tampered'])
            ->assertStatus(403);

        $this->assertDatabaseHas('weekly_activity_logs', [
            'id' => $sheet->id,
            'area_assigned' => 'Accounting Office',
        ]);
    }
}
