<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Observers\UserObserver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['program_id', 'name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
#[ObservedBy([UserObserver::class])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'student_id');
    }

    public function weeklyLogsAsStudent(): HasMany
    {
        return $this->hasMany(WeeklyLog::class, 'student_id');
    }

    public function weeklyLogsAsSupervisor(): HasMany
    {
        return $this->hasMany(WeeklyLog::class, 'supervisor_id');
    }

    public function batchesCoordinated(): HasMany
    {
        return $this->hasMany(Batch::class, 'coordinator_id');
    }

    public function companySupervisorAssignments(): HasMany
    {
        return $this->hasMany(CompanySupervisor::class);
    }

    public function batchEnrollment(): HasOne
    {
        return $this->hasOne(BatchStudent::class, 'student_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
