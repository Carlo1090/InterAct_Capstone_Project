<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'weekly_activity_log_id', 'inclusive_date_start', 'inclusive_date_end', 'activities',
    'documents_records', 'objectives', 'supervisor_name', 'supervisor_position', 'sort_order',
])]
class WeeklyActivityEntry extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'inclusive_date_start' => 'date',
            'inclusive_date_end' => 'date',
        ];
    }

    public function weeklyActivityLog(): BelongsTo
    {
        return $this->belongsTo(WeeklyActivityLog::class);
    }
}
