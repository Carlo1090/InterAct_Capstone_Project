<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'student_id', 'batch_id', 'geofence_id', 'work_date',
    'time_in', 'time_in_lat', 'time_in_lng', 'time_in_accuracy', 'time_in_distance',
    'time_out', 'time_out_lat', 'time_out_lng', 'time_out_accuracy', 'time_out_distance',
    'minutes_worked', 'status', 'adjusted_by', 'adjustment_reason', 'open_session_key',
])]
class DtrSession extends Model
{
    /** Statuses whose minutes count toward completed OJT hours. */
    public const COUNTED_STATUSES = ['closed'];

    /**
     * Keep open_session_key in lockstep with status on every save, so no
     * caller can forget it and leave a student unable to ever clock in again.
     *
     * The key holds student_id while open and NULL otherwise; a unique index
     * on it is what makes "one open session per student" a database
     * guarantee rather than a check the service hopes it won a race on.
     */
    protected static function booted(): void
    {
        static::saving(function (DtrSession $session) {
            $session->open_session_key = $session->status === 'open' ? $session->student_id : null;
        });
    }

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'time_in' => 'datetime',
            'time_out' => 'datetime',
            'time_in_lat' => 'float',
            'time_in_lng' => 'float',
            'time_out_lat' => 'float',
            'time_out_lng' => 'float',
            'minutes_worked' => 'integer',
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

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(CompanyGeofence::class, 'geofence_id');
    }

    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
