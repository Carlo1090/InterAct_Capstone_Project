<?php

namespace Tests\Feature\Student;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class ReminderPreferenceTest extends TestCase
{
    use EnrollsStudentInBatch;
    use RefreshDatabase;

    private function profileFor(User $student): StudentProfile
    {
        return StudentProfile::where('user_id', $student->id)->firstOrFail();
    }

    public function test_an_untouched_profile_reports_no_preference_and_the_batch_defaults(): void
    {
        $student = $this->enrolledStudent(['working_days_per_week' => 5, 'daily_reminder_time' => '21:00:00']);
        Sanctum::actingAs($student, ['*']);

        $response = $this->getJson('/api/student/reminder-preferences');

        $response->assertOk();
        $response->assertJson([
            'reminder_enabled' => true,
            'reminder_days' => null,
            'reminder_time' => null,
            'defaults' => [
                'days' => [1, 2, 3, 4, 5],
                'time' => '21:00',
            ],
        ]);
    }

    public function test_a_six_day_batch_reports_saturday_in_its_defaults(): void
    {
        $student = $this->enrolledStudent(['working_days_per_week' => 6]);
        Sanctum::actingAs($student, ['*']);

        $this->getJson('/api/student/reminder-preferences')
            ->assertOk()
            ->assertJsonPath('defaults.days', [1, 2, 3, 4, 5, 6]);
    }

    public function test_a_student_can_save_their_own_days_and_time(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $response = $this->putJson('/api/student/reminder-preferences', [
            'reminder_enabled' => true,
            // Deliberately out of order and duplicated — storage must normalize.
            'reminder_days' => [6, 1, 3, 1],
            'reminder_time' => '06:30',
        ]);

        $response->assertOk();
        $response->assertJsonPath('reminder_days', [1, 3, 6]);
        $response->assertJsonPath('reminder_time', '06:30');

        $profile = $this->profileFor($student);
        $this->assertSame('1,3,6', $profile->reminder_days);
        $this->assertTrue($profile->reminder_enabled);
    }

    /**
     * Empty days means "follow my batch's working days", NOT "never remind me" —
     * switching reminders off is what reminder_enabled is for. Storing null is
     * what makes the fallback in ReminderSchedule kick back in.
     */
    public function test_clearing_the_days_falls_back_to_the_batch_pattern(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $this->putJson('/api/student/reminder-preferences', [
            'reminder_enabled' => true,
            'reminder_days' => [1, 2],
            'reminder_time' => '08:00',
        ])->assertOk();

        $this->putJson('/api/student/reminder-preferences', [
            'reminder_enabled' => true,
            'reminder_days' => [],
            'reminder_time' => null,
        ])->assertOk()->assertJsonPath('reminder_days', null)->assertJsonPath('reminder_time', null);

        $profile = $this->profileFor($student);
        $this->assertNull($profile->reminder_days);
        $this->assertNull($profile->reminder_time);
    }

    public function test_a_student_can_switch_reminders_off(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $this->putJson('/api/student/reminder-preferences', [
            'reminder_enabled' => false,
        ])->assertOk()->assertJsonPath('reminder_enabled', false);

        $this->assertFalse($this->profileFor($student)->reminder_enabled);
    }

    public function test_it_rejects_an_out_of_range_day_and_a_malformed_time(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $this->putJson('/api/student/reminder-preferences', [
            'reminder_enabled' => true,
            'reminder_days' => [1, 8],
            'reminder_time' => 'half past nine',
        ])->assertStatus(422)->assertJsonValidationErrors(['reminder_days.1', 'reminder_time']);
    }

    public function test_a_non_student_cannot_reach_the_endpoint(): void
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        Sanctum::actingAs($coordinator, ['*']);

        $this->getJson('/api/student/reminder-preferences')->assertStatus(403);
    }
}
