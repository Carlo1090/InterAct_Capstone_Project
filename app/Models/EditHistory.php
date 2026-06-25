<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['weekly_log_id', 'edited_by', 'previous_content', 'new_content', 'action'])]
class EditHistory extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'edited_at';
    const UPDATED_AT = null;

    public function weeklyLog(): BelongsTo
    {
        return $this->belongsTo(WeeklyLog::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
