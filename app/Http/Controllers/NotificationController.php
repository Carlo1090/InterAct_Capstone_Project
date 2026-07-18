<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service notification center shared by every role — mirrors
 * ProfileController's un-namespaced pattern since the behavior is identical
 * regardless of role. Backs the header bell/red-dot indicator: the
 * notifications table and User::notifications() already existed (written to
 * by SendMissingJournalEntryReminders), this is what reads them back.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->orderByDesc('sent_at')
            ->paginate(20);

        return response()->json([
            'data' => $notifications->items(),
            'unread_count' => $user->notifications()->where('is_read', false)->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->notifications()->where('is_read', false)->update(['is_read' => true]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->update(['is_read' => true]);

        return response()->json($notification->fresh());
    }
}
