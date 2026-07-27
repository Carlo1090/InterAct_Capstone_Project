<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfilePhotoRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\SystemLog;
use App\Services\AvatarProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $validated = $request->validated();

        // A hand-typed address is unverified by definition. Clearing the stamp
        // matters twice over: it stops reminder mail going to an unconfirmed
        // inbox, and — since a non-null email_verified_at is exactly what
        // authorizes Google sign-in — it revokes that too. Editing your email
        // is therefore also how you unlink your Google account.
        $emailChanged = array_key_exists('email', $validated) && $validated['email'] !== $user->email;

        $user->update($validated);

        if ($emailChanged) {
            // forceFill: email_verified_at is deliberately not mass-assignable.
            $user->forceFill(['email_verified_at' => null])->save();
        }

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

    public function uploadPhoto(UpdateProfilePhotoRequest $request, AvatarProcessingService $avatars): JsonResponse
    {
        $user = $request->user();

        $disk = config('filesystems.avatars');

        $avatar = $avatars->toAvatarImage($request->file('photo')->get());
        $path = 'avatars/'.Str::random(40).'.'.$avatar['extension'];
        Storage::disk($disk)->put($path, $avatar['binary']);

        $previousPath = $user->avatar_path;
        $user->update(['avatar_path' => $path]);

        if ($previousPath) {
            Storage::disk($disk)->delete($previousPath);
        }

        SystemLog::record('Profile Photo Updated', "{$user->name} updated their profile photo");

        return response()->json($user->fresh());
    }

    public function deletePhoto(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk(config('filesystems.avatars'))->delete($user->avatar_path);
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
}
