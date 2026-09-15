<?php

namespace App\Models;

use App\Support\ExitInterview\ExitInterviewForm;
use App\Support\ExitInterview\ExitInterviewForms;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['student_id', 'batch_id', 'form_key', 'student_info', 'responses', 'coordinator_section', 'submission_status', 'submitted_at', 'reviewed_at', 'reviewed_by'])]
class StudentExitInterview extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'student_info' => 'array',
            'responses' => 'array',
            'coordinator_section' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * The form this interview was answered under — its own snapshot
     * (`form_key`), which is what makes the question set stable for the life
     * of the row. The question keys `responses` is keyed by, the Yes/No
     * `*_choice` keys and any rating scale all come from here; nothing about
     * the question set lives on this model.
     */
    public function form(): ExitInterviewForm
    {
        return ExitInterviewForms::get($this->form_key);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
