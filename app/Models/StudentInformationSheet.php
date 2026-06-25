<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['student_id', 'batch_id', 'personal_info', 'academic_info', 'ojt_info', 'emergency_contact', 'submission_status', 'submitted_at'])]
class StudentInformationSheet extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'personal_info' => 'array',
            'academic_info' => 'array',
            'ojt_info' => 'array',
            'emergency_contact' => 'array',
            'submitted_at' => 'datetime',
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
}
