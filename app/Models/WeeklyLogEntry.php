<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['weekly_log_id', 'journal_entry_id'])]
class WeeklyLogEntry extends Model
{
    public $timestamps = false;

    public function weeklyLog(): BelongsTo
    {
        return $this->belongsTo(WeeklyLog::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
