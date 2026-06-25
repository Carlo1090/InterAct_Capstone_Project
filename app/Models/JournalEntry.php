<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['student_id', 'batch_id', 'entry_date', 'content', 'status', 'submitted_at'])]
class JournalEntry extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'content' => 'array',
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

    public function weeklyLogEntries(): HasMany
    {
        return $this->hasMany(WeeklyLogEntry::class);
    }
}
