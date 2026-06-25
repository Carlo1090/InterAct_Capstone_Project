<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['student_id', 'batch_id', 'weekly_log_id', 'week_start', 'week_end', 'area_assigned', 'no_of_hours', 'status', 'submitted_at'])]
class WeeklyActivityLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'week_end' => 'date',
            'no_of_hours' => 'decimal:1',
            'submitted_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function weeklyLog(): BelongsTo
    {
        return $this->belongsTo(WeeklyLog::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WeeklyActivityEntry::class);
    }
}
