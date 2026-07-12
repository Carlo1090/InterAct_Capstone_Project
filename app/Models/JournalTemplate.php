<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sections', 'char_limit', 'is_active'])]
class JournalTemplate extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'char_limit' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * A template covers one or more programs, via the journal_template_program
     * pivot. Each program can belong to at most one template (unique(program_id)).
     */
    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'journal_template_program');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }
}
