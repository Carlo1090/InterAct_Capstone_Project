<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProfile extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'company_name',
        'address',
        'total_hours_required',
        'student_id_number',
        'middle_name',
        'date_of_birth',
        'sex',
        'contact_number',
        'home_address',
        'year_level',
        'reminder_days',
        'reminder_time',
        'reminder_enabled',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'total_hours_required' => 'integer',
            'reminder_enabled' => 'boolean',
        ];
    }

    /**
     * The student's chosen reminder days as ISO day numbers (1 = Mon .. 7 = Sun),
     * or null when they have never set them — which means "fall back to the
     * batch's working days". See App\Support\ReminderSchedule.
     *
     * reminder_time is deliberately left uncast: it is stored as a plain H:i:s
     * string and MySQL and SQLite hand it back in different shapes, so callers
     * parse it with Carbon rather than trusting a cast.
     *
     * @return list<int>|null
     */
    public function reminderDayNumbers(): ?array
    {
        $raw = trim((string) $this->reminder_days);

        if ($raw === '') {
            return null;
        }

        $days = collect(explode(',', $raw))
            ->map(fn (string $day) => (int) trim($day))
            ->filter(fn (int $day) => $day >= 1 && $day <= 7)
            ->unique()
            ->values()
            ->all();

        return $days === [] ? null : $days;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
