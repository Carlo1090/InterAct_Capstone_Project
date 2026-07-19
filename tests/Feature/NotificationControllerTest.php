<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_only_returns_the_authenticated_users_own_notifications_with_unread_count(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $otherUser = User::factory()->create(['role' => 'student']);

        Notification::create(['user_id' => $user->id, 'title' => 'Mine, unread', 'message' => 'A', 'type' => 'email', 'is_read' => false]);
        Notification::create(['user_id' => $user->id, 'title' => 'Mine, read', 'message' => 'B', 'type' => 'email', 'is_read' => true]);
        Notification::create(['user_id' => $otherUser->id, 'title' => 'Not mine', 'message' => 'C', 'type' => 'email', 'is_read' => false]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/notifications');

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Mine, unread'));
        $this->assertTrue($titles->contains('Mine, read'));
        $this->assertFalse($titles->contains('Not mine'));
        $this->assertSame(1, $response->json('unread_count'));
    }

    public function test_mark_all_read_only_touches_the_authenticated_users_own_unread_notifications(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $otherUser = User::factory()->create(['role' => 'student']);

        $mine = Notification::create(['user_id' => $user->id, 'title' => 'Mine', 'message' => 'A', 'type' => 'email', 'is_read' => false]);
        $theirs = Notification::create(['user_id' => $otherUser->id, 'title' => 'Theirs', 'message' => 'B', 'type' => 'email', 'is_read' => false]);

        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/notifications/read-all')->assertOk();

        $this->assertTrue($mine->fresh()->is_read);
        $this->assertFalse($theirs->fresh()->is_read);
    }

    public function test_mark_read_is_forbidden_for_someone_elses_notification(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $otherUser = User::factory()->create(['role' => 'student']);
        $theirs = Notification::create(['user_id' => $otherUser->id, 'title' => 'Theirs', 'message' => 'B', 'type' => 'email', 'is_read' => false]);

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/notifications/{$theirs->id}/read")->assertStatus(403);
        $this->assertFalse($theirs->fresh()->is_read);
    }

    public function test_mark_read_marks_a_single_own_notification(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $mine = Notification::create(['user_id' => $user->id, 'title' => 'Mine', 'message' => 'A', 'type' => 'email', 'is_read' => false]);

        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/notifications/{$mine->id}/read")->assertOk();
        $this->assertTrue($mine->fresh()->is_read);
    }

    public function test_clear_all_deletes_only_the_authenticated_users_own_notifications(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $otherUser = User::factory()->create(['role' => 'student']);

        $mine = Notification::create(['user_id' => $user->id, 'title' => 'Mine', 'message' => 'A', 'type' => 'email', 'is_read' => false]);
        $theirs = Notification::create(['user_id' => $otherUser->id, 'title' => 'Theirs', 'message' => 'B', 'type' => 'email', 'is_read' => false]);

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson('/api/notifications')->assertOk();

        $this->assertDatabaseMissing('notifications', ['id' => $mine->id]);
        $this->assertDatabaseHas('notifications', ['id' => $theirs->id]);
    }
}
