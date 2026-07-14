<?php

namespace App\Models;

use App\Observers\UserObserver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
        'username',
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

    /**
     * Guarantee every user has a unique username even when a creator (seeder,
     * factory fallback, tinker) doesn't supply one — derived from the email
     * local-part, then name/role, then a generic base. An explicitly-set
     * username always wins.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (blank($user->username)) {
                $base = filled($user->email)
                    ? Str::before($user->email, '@')
                    : ($user->name ?: $user->role);

                $user->username = static::generateUniqueUsername($base);
            }
        });
    }

    /**
     * Slug a base string into a unique username, appending a numeric suffix
     * until it no longer collides with an existing row.
     */
    public static function generateUniqueUsername(?string $base): string
    {
        $slug = Str::of((string) $base)->lower()->replaceMatches('/[^a-z0-9._-]+/', '')->trim('._-')->value();

        if ($slug === '') {
            $slug = 'user';
        }

        $candidate = $slug;
        $suffix = 1;

        while (static::where('username', $candidate)->exists()) {
            $candidate = $slug.$suffix;
            $suffix++;
        }

        return $candidate;
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

    /**
     * Whether this student is still behind the info-sheet enrollment gate.
     * The gate lifts once their sheet is approved — or, for legacy/direct
     * enrollments that predate the intake flow, once they have any active
     * batch_students row (an already-enrolled student has cleared intake).
     */
    public function isInfoSheetGated(): bool
    {
        if ($this->role !== 'student') {
            return false;
        }

        if ($this->studentInformationSheets()->where('submission_status', 'approved')->exists()) {
            return false;
        }

        return ! BatchStudent::where('student_id', $this->id)->where('status', 'active')->exists();
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

    public function departmentsCoordinated(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'coordinator_departments', 'coordinator_id', 'department_id');
    }

    /**
     * Distinct program IDs this coordinator has scope over: every program in
     * their assigned department(s), merged with the programs of batches they
     * already coordinate. The batch merge is backward safety only, so a
     * coordinator's scope never shrinks below what's already in use.
     */
    public function coordinatorProgramIds(): Collection
    {
        $departmentIds = $this->departmentsCoordinated()->pluck('departments.id');

        return Program::whereIn('department_id', $departmentIds)->pluck('id')
            ->merge($this->batchesCoordinated()->pluck('program_id'))
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
