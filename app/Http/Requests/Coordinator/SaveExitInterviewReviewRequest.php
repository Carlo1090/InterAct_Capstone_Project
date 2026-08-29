<?php

namespace App\Http\Requests\Coordinator;

use App\Support\ExitInterviewFormLayout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "SECTION FOR OJT/INTERNSHIP COORDINATOR" — the block the paper form reserves
 * at the foot of page 2. Nothing here touches the student's answers.
 *
 * The two free-text fields are capped for the same reason the student's are:
 * they print onto a fixed run of ruled lines, and text past the last line
 * would be dropped silently.
 */
class SaveExitInterviewReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        return [
            'compliance' => ['nullable', Rule::in(['complete', 'pending'])],
            'pending_detail' => ['nullable', 'string', 'max:'.ExitInterviewFormLayout::HARD_CHAR_CAP],
            'remarks' => ['nullable', 'string', 'max:'.ExitInterviewFormLayout::HARD_CHAR_CAP],
        ];
    }

    /**
     * Same width measurement as the student's own answers — both print onto a
     * fixed run of ruled lines, so both must be refused rather than truncated.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Both fields are laid onto printed rules exactly like a student's
            // answer, so both go through the same width check rather than a
            // hand-written one that could drift from the layout.
            foreach (['pending_detail', 'remarks'] as $field) {
                if (ExitInterviewFormLayout::fits($field, $this->input($field))) {
                    continue;
                }

                $lines = count(ExitInterviewFormLayout::rules()[$field]);

                $validator->errors()->add(
                    $field,
                    "This is longer than the {$lines} lines the printed form leaves for it. Please shorten it."
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'compliance' => 'Compliance Verification',
            'pending_detail' => 'Pending requirements',
            'remarks' => 'Remarks',
        ];
    }
}
