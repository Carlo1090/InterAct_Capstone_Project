<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['batch_id', 'student_id', 'company_id', 'supervisor_id', 'assigned_division', 'status'])]
class BatchStudent extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'enrolled_at';
    const UPDATED_AT = null;

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
}
