<?php

namespace App\Models;

use App\Observers\UserObserver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
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
        'avatar_path',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'avatar_url',
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

    /**
     * Public URL of the uploaded avatar, or null so the frontend can fall
     * back to initials — never fabricate a URL for a missing file.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
        );
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
     * enrollments that predate the intake flow, once they have an active
     * OR completed batch_students row (either proves they cleared intake;
     * marking a legacy student's OJT completed must not re-gate them and
     * lock them out of reading their own journals).
     */
    public function isInfoSheetGated(): bool
    {
        if ($this->role !== 'student') {
            return false;
        }

        if ($this->studentInformationSheets()->where('submission_status', 'approved')->exists()) {
            return false;
        }

        return ! BatchStudent::where('student_id', $this->id)->whereIn('status', ['active', 'completed'])->exists();
    }

    /**
     * A student who cleared intake (has an approved information sheet) but whose
     * enrollment is no longer active or completed — i.e. a coordinator dropped
     * them from their batch. Their write endpoints already 422 and their read
     * endpoints have no current enrollment to resolve, so the frontend shows a
     * calm "enrollment inactive" state instead of erroring pages. Distinct from
     * isInfoSheetGated(): a paused student is past intake, not still in it.
     */
    public function isEnrollmentPaused(): bool
    {
        if ($this->role !== 'student') {
            return false;
        }

        // Still in intake (no approved sheet) — that's the gate's job, not this.
        if ($this->isInfoSheetGated()) {
            return false;
        }

        return ! BatchStudent::where('student_id', $this->id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();
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
