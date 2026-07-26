<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * sent_at is fillable even though $timestamps is false: the column carries a
 * useCurrent() DB default, but that uses the DATABASE clock, which ignores
 * Carbon test-time travel. Writers that need to query their own rows back by
 * date (see SendMissingJournalEntryReminders' same-day dedupe) must set it
 * explicitly; everyone else can keep relying on the default.
 */
#[Fillable(['user_id', 'title', 'message', 'type', 'is_read', 'sent_at'])]
class Notification extends Model
{
    public $timestamps = false;

    const CREATED_AT = 'sent_at';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
