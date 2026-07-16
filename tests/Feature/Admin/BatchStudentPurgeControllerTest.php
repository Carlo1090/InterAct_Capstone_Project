<?php

namespace Tests\Feature\Admin;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BatchStudentPurgeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function archivedRow(): BatchStudent
    {
        $department = Department::firstOrCreate(['code' => 'CABM-B'], ['name' => 'CABM-B', 'is_active' => true]);
        $program = Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => 'BSA'],
            ['name' => 'BSA', 'is_active' => true]
        );
        $coordinator = User::factory()->create(['role' => 'coordinator']);

        $batch = Batch::create([
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

        $company = Company::create(['name' => 'Co '.uniqid(), 'address' => 'Bohol', 'is_active' => true]);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        $row = BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'dropped',
        ]);
        $row->archived_at = now()->subDays(31);
        $row->save();

        return $row;
    }

    public function test_admin_can_trigger_purge_and_it_removes_an_old_archived_row(): void
    {
        $row = $this->archivedRow();

        Sanctum::actingAs($this->admin(), ['*']);

        $response = $this->postJson('/api/admin/roster/purge-archived/run');

        $response->assertOk();
        $response->assertJsonStructure(['purged', 'cutoff']);
        $response->assertJsonPath('purged', 1);
        $this->assertDatabaseMissing('batch_students', ['id' => $row->id]);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/admin/roster/purge-archived/run')->assertStatus(403);
    }
}
