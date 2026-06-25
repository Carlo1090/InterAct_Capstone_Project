<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['batch_id', 'student_id', 'supervisor_id', 'week_start', 'week_end', 'status', 'supervisor_comment', 'submitted_at', 'reviewed_at'])]
class WeeklyLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'week_end' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function weeklyLogEntries(): HasMany
    {
        return $this->hasMany(WeeklyLogEntry::class);
    }

    public function editHistory(): HasMany
    {
        return $this->hasMany(EditHistory::class);
    }

    public function weeklyActivityLog(): HasMany
    {
        return $this->hasMany(WeeklyActivityLog::class);
    }
}
