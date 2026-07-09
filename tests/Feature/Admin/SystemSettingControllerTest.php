<?php

namespace Tests\Feature\Admin;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemSettingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_index_returns_all_known_keys_with_null_defaults(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $response = $this->getJson('/api/admin/system-settings');

        $response->assertOk();
        $response->assertJson([
            'system_name' => null,
            'institution_name' => null,
            'institution_address' => null,
            'system_email' => null,
        ]);
    }

    public function test_update_persists_and_returns_the_new_values(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $response = $this->putJson('/api/admin/system-settings', [
            'system_name' => 'InternTrack',
            'institution_name' => 'Mater Dei College',
        ]);

        $response->assertOk();
        $response->assertJsonPath('system_name', 'InternTrack');
        $response->assertJsonPath('institution_name', 'Mater Dei College');

        $this->assertDatabaseHas('system_settings', ['key' => 'system_name', 'value' => 'InternTrack']);

        // Re-saving only one key doesn't wipe the other.
        $this->putJson('/api/admin/system-settings', ['system_name' => 'InternTrack Updated'])->assertOk();
        $this->assertDatabaseHas('system_settings', ['key' => 'institution_name', 'value' => 'Mater Dei College']);
    }

    public function test_update_ignores_keys_outside_the_whitelist(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $this->putJson('/api/admin/system-settings', ['not_a_real_setting' => 'hacked'])->assertOk();

        $this->assertDatabaseMissing('system_settings', ['key' => 'not_a_real_setting']);
    }

    public function test_non_admin_cannot_update_system_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']), ['*']);

        $this->putJson('/api/admin/system-settings', ['system_name' => 'Hacked'])->assertStatus(403);
    }
}
