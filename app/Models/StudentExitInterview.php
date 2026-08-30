<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['student_id', 'batch_id', 'student_info', 'responses', 'coordinator_section', 'submission_status', 'submitted_at', 'reviewed_at', 'reviewed_by'])]
class StudentExitInterview extends Model
{
    public $timestamps = false;

    /**
     * The fourteen numbered questions on the CABM paper form, in the order
     * they are printed. The keys are what `responses` is keyed by, so this
     * array is the single definition shared by the Form Request, the API
     * payload and the PDF — the three can never disagree about what question
     * 7 is.
     */
    public const QUESTION_KEYS = [
        'q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7',
        'q8', 'q9', 'q10', 'q11', 'q12', 'q13', 'q14',
    ];

    /**
     * The four questions that carry a printed ☐ Yes ☐ No pair alongside their
     * explanation lines. Stored as `q2_choice` etc. so the free text keeps the
     * plain question key.
     */
    public const CHOICE_KEYS = ['q2_choice', 'q7_choice', 'q10_choice', 'q11_choice'];

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
