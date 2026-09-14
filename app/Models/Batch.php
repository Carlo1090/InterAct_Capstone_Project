<?php

namespace App\Models;

use App\Observers\BatchObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([BatchObserver::class])]
class Batch extends Model
{
    /**
     * The two OJT mechanics a cohort can run under.
     *
     * `supervisor` — the host company holds a login; its supervisor reviews the
     * weekly journals and corrects the time records. This is how every batch
     * behaved before the choice existed, and is the column default.
     *
     * `coordinator` — there is no company supervisor at all. The coordinator
     * reviews the weekly journals themselves, and the supervisor fields that
     * still print on the paper forms are informational text rather than a
     * linked account.
     */
    public const OJT_TYPE_SUPERVISOR = 'supervisor';

    public const OJT_TYPE_COORDINATOR = 'coordinator';

    /**
     * @var list<string>
     */
    public const OJT_TYPES = [self::OJT_TYPE_SUPERVISOR, self::OJT_TYPE_COORDINATOR];

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'program_id',
        'coordinator_id',
        'name',
        'start_date',
        'end_date',
        'required_hours',
        'working_days_per_week',
        'working_days_start',
        'working_days_end',
        'daily_reminder_time',
        'journal_template_id',
        'ojt_type',
        'academic_year',
        'semester',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Reads the column defensively: a batch created before the column existed
     * (or hydrated without it) is supervisor-supported, which is what every
     * batch was.
     */
    public function isCoordinatorCentered(): bool
    {
        return $this->ojt_type === self::OJT_TYPE_COORDINATOR;
    }

    public function isSupervisorSupported(): bool
    {
        return ! $this->isCoordinatorCentered();
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function journalTemplate(): BelongsTo
    {
        return $this->belongsTo(JournalTemplate::class);
    }

    public function batchStudents(): HasMany
    {
        return $this->hasMany(BatchStudent::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function weeklyLogs(): HasMany
    {
        return $this->hasMany(WeeklyLog::class);
    }

    public function weeklyActivityLogs(): HasMany
    {
        return $this->hasMany(WeeklyActivityLog::class);
    }

    public function studentInformationSheets(): HasMany
    {
        return $this->hasMany(StudentInformationSheet::class);
    }

    public function sippAnnualReports(): HasMany
    {
        return $this->hasMany(SippAnnualReport::class);
    }
}
