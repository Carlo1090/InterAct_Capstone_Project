<?php

namespace App\Models;

use App\Observers\UserObserver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

#[ObservedBy([UserObserver::class])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'student_id_number',
        'program_id',
        'is_active',
        'must_change_password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
            'must_change_password' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isCoordinator(): bool
    {
        return $this->role === 'coordinator';
    }

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'student_id');
    }

    public function studentInformationSheets(): HasMany
    {
        return $this->hasMany(StudentInformationSheet::class, 'student_id');
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

    /**
     * Distinct program IDs this coordinator has scope over: their assigned
     * `program_id` merged with the programs of batches they already coordinate.
     * Keeps a coordinator with an assigned program but zero batches yet from
     * being locked out of program-scoped actions (e.g. creating their first batch).
     */
    public function coordinatorProgramIds(): Collection
    {
        return $this->batchesCoordinated()->pluck('program_id')
            ->when($this->program_id, fn (Collection $ids) => $ids->push($this->program_id))
            ->unique()
            ->values();
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
