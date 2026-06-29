<?php

namespace App\Observers;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Str;

class UserObserver
{
    /**
     * Handle the User "created" event.
     *
     * Auto-creates a student_profiles row whenever a student account is
     * created, regardless of whether it came from the admin endpoint, a
     * seeder, or artisan tinker. A temporary, unique placeholder is used for
     * student_id_number since the real one is assigned by the registrar and
     * filled in later — the student or coordinator can update it afterward.
     */
    public function created(User $user): void
    {
        if ($user->role === 'student' && ! $user->studentProfile) {
            StudentProfile::create([
                'user_id' => $user->id,
                'student_id_number' => 'PENDING-'.Str::upper(Str::random(8)),
            ]);
        }
    }
}
