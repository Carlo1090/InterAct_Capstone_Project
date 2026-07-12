<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Program extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /**
     * A program is covered by at most one template (unique(program_id) on the
     * pivot), but this is modelled as belongsToMany so the pivot stays the sole
     * source of truth. Use ->journalTemplates()->first() for the single one.
     */
    public function journalTemplates(): BelongsToMany
    {
        return $this->belongsToMany(JournalTemplate::class, 'journal_template_program');
    }

    public function batchStudents(): HasManyThrough
    {
        return $this->hasManyThrough(BatchStudent::class, Batch::class);
    }
}
