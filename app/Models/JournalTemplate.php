<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['program_id', 'name', 'sections', 'word_limit', 'is_active'])]
class JournalTemplate extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'word_limit' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }
}
