<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
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
        'daily_reminder_time',
        'journal_template_id',
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
