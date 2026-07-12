<?php

namespace Tests\Feature\Admin;

use App\Models\JournalEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class WeeklyBundlingControllerTest extends TestCase
{
    use RefreshDatabase;
    use EnrollsStudentInBatch;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_can_trigger_bundling_for_a_specified_week(): void
    {
        $student = $this->enrolledStudent();
        $monday = Carbon::parse('2026-06-29'); // a known Monday

        JournalEntry::create([
            'student_id' => $student->id,
            'batch_id' => $student->batchEnrollment->batch_id,
            'entry_date' => $monday->toDateString(),
            'content' => ['daily_accomplishment' => 'Demo day content.'],
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($this->admin(), ['*']);

        $response = $this->postJson('/api/admin/weekly-bundling/run', ['week_start' => $monday->toDateString()]);

        $response->assertOk();
        $response->assertJsonPath('week_start', $monday->toDateString());
        $response->assertJsonPath('compiled', 1);

        $this->assertDatabaseHas('weekly_logs', [
            'student_id' => $student->id,
            'narrative' => "MONDAY\nDemo day content.",
        ]);
    }

    public function test_defaults_to_the_most_recently_completed_week_when_omitted(): void
    {
        $this->enrolledStudent();
        Sanctum::actingAs($this->admin(), ['*']);

        $response = $this->postJson('/api/admin/weekly-bundling/run');

        $response->assertOk();
        $response->assertJsonStructure(['week_start', 'week_end', 'compiled', 'skipped_submitted']);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        Sanctum::actingAs($coordinator, ['*']);

        $this->postJson('/api/admin/weekly-bundling/run')->assertStatus(403);
    }
}
