<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['coordinator_id', 'company_id', 'academic_year', 'sheet_data', 'status'])]
class GroupInfoSheet extends Model
{
    public $timestamps = false;

    const CREATED_AT = 'generated_at';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'sheet_data' => 'array',
        ];
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
