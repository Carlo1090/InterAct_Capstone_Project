<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['batch_id', 'student_id', 'company_id', 'supervisor_id', 'company_supervisor_id', 'assigned_division', 'status'])]
class BatchStudent extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'enrolled_at';
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    /**
     * completed_at mirrors status on every instance save: stamped when the
     * row becomes 'completed' (and left alone if already stamped), cleared
     * whenever the row is anything else. Every status change in the app goes
     * through an instance save (update()/save()), so no caller needs to
     * manage the timestamp itself.
     */
    protected static function booted(): void
    {
        static::saving(function (BatchStudent $enrollment) {
            if ($enrollment->status === 'completed') {
                $enrollment->completed_at ??= now();
            } else {
                $enrollment->completed_at = null;
            }
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function companySupervisor(): BelongsTo
    {
        return $this->belongsTo(CompanySupervisor::class);
    }
}
