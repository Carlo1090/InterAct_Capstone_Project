<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['coordinator_id', 'program_id', 'batch_id', 'academic_year', 'report_data', 'status'])]
class SippAnnualReport extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'generated_at';
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'report_data' => 'array',
        ];
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
