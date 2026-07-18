<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfilePhotoRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Self-service account settings shared by every role (admin/coordinator/
 * supervisor/student) — profile fields, password, avatar, and a personal
 * activity history. Lives outside any role-namespaced controller/route
 * group since the behavior is identical regardless of role.
 */
class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        SystemLog::record('Profile Updated', "{$user->name} updated their profile");

        return response()->json($user->fresh());
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $user->update([
            'password' => $validated['password'],
            'must_change_password' => false,
        ]);

        SystemLog::record('Password Changed', "{$user->name} changed their password");

        return response()->json(['message' => 'Password updated.']);
    }

    public function uploadPhoto(UpdateProfilePhotoRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('photo')->store('avatars', 'public');
        $this->reencodeImage(Storage::disk('public')->path($path));
        $user->update(['avatar_path' => $path]);

        SystemLog::record('Profile Photo Updated', "{$user->name} updated their profile photo");

        return response()->json($user->fresh());
    }

    public function deletePhoto(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);

            SystemLog::record('Profile Photo Removed', "{$user->name} removed their profile photo");
        }

        return response()->json($user->fresh());
    }

    /**
     * The authenticated user's own action history — the personal counterpart
     * to the admin-wide Audit Logs page, scoped to system_logs rows they
     * themselves caused.
     */
    public function activity(Request $request): JsonResponse
    {
        $logs = SystemLog::where('user_id', $request->user()->id)
            ->orderByDesc('logged_at')
            ->paginate(20);

        return response()->json($logs);
    }

    /**
     * Re-encode an uploaded avatar in place so any non-image payload smuggled
     * inside a technically-valid image container (a "polyglot" file) can't
     * survive on disk — defense in depth beyond the mime/extension check
     * UpdateProfilePhotoRequest already enforces.
     */
    private function reencodeImage(string $fullPath): void
    {
        $image = @imagecreatefromstring(file_get_contents($fullPath));

        if ($image === false) {
            return;
        }

        match (strtolower(pathinfo($fullPath, PATHINFO_EXTENSION))) {
            'png' => imagepng($image, $fullPath),
            'webp' => imagewebp($image, $fullPath),
            default => imagejpeg($image, $fullPath, 90),
        };

        imagedestroy($image);
    }
}
