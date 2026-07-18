<?php

namespace Tests\Feature;

use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    public static function roleProvider(): array
    {
        return [
            'admin' => ['admin'],
            'coordinator' => ['coordinator'],
            'supervisor' => ['supervisor'],
            'student' => ['student'],
        ];
    }

    #[DataProvider('roleProvider')]
    public function test_wrong_current_password_is_rejected(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson('/api/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['current_password']);
    }

    #[DataProvider('roleProvider')]
    public function test_user_can_change_their_password_and_the_flag_clears(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'must_change_password' => true]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123',
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('a-new-password-123', $user->password));
        $this->assertDatabaseHas('system_logs', ['user_id' => $user->id, 'action' => 'Password Changed']);
    }

    public function test_new_password_must_differ_from_current(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_user_can_update_their_profile_fields(): void
    {
        $user = User::factory()->create(['role' => 'coordinator', 'name' => 'Old Name']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson('/api/profile', [
            'name' => 'New Name',
            'username' => $user->username,
            'email' => 'new-email@example.com',
        ]);

        $response->assertOk();
        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new-email@example.com', $user->email);
        $this->assertDatabaseHas('system_logs', ['user_id' => $user->id, 'action' => 'Profile Updated']);
    }

    public function test_profile_update_rejects_a_username_already_taken_by_someone_else(): void
    {
        User::factory()->create(['role' => 'student', 'username' => 'taken-username']);
        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->putJson('/api/profile', [
            'name' => $user->name,
            'username' => 'taken-username',
            'email' => $user->email,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['username']);
    }

    public function test_user_can_upload_and_remove_a_profile_photo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->post('/api/profile/photo', [
            'photo' => UploadedFile::fake()->image('avatar.jpg', 800, 600),
        ]);

        $response->assertOk();
        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        $this->assertStringEndsWith('.webp', $user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
        $this->assertNotNull($response->json('avatar_url'));

        // Standardized to a 250x250 WebP square regardless of the uploaded
        // photo's original dimensions/format.
        $stored = Storage::disk('public')->get($user->avatar_path);
        $info = getimagesizefromstring($stored);
        $this->assertSame(250, $info[0]);
        $this->assertSame(250, $info[1]);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);

        $deleteResponse = $this->delete('/api/profile/photo');
        $deleteResponse->assertOk();

        $oldPath = $user->avatar_path;
        $user->refresh();
        $this->assertNull($user->avatar_path);
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_profile_photo_upload_rejects_a_non_image_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user, ['*']);

        $response = $this->post('/api/profile/photo', [
            'photo' => UploadedFile::fake()->create('resume.pdf', 100),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photo']);
    }

    public function test_profile_photo_upload_rejects_garbage_bytes_disguised_as_an_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($user, ['*']);

        // A spoofed extension + declared MIME type, but the actual file
        // content is not a genuine image — this is exactly what the mimes
        // rule alone can miss on a fake upload, and what the magic-byte
        // check in AvatarProcessingService::sniffType() is meant to catch.
        $response = $this->post('/api/profile/photo', [
            'photo' => UploadedFile::fake()->create('malicious.jpg', 50, 'image/jpeg'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['photo']);
    }

    public function test_activity_log_only_returns_the_authenticated_users_own_entries(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $otherUser = User::factory()->create(['role' => 'student']);

        SystemLog::create(['user_id' => $user->id, 'action' => 'Logged In']);
        SystemLog::create(['user_id' => $otherUser->id, 'action' => 'Logged In']);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/profile/activity');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($user->id, $data[0]['user_id']);
    }
}
