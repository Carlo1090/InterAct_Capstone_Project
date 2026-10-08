<?php

namespace App\Http\Requests\Coordinator;

use App\Models\StudentExitInterview;
use App\Support\ExitInterview\ExitInterviewForms;
use App\Support\ExitInterviewFormLayout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "SECTION FOR OJT/INTERNSHIP COORDINATOR" — the block the paper form reserves
 * at the foot of the last page. Nothing here touches the student's answers.
 *
 * The block is the TEMPLATE's, so it is identical on every department's form;
 * only the layout instance (and so the page it lands on) is the interview's
 * own.
 *
 * The two free-text fields are capped for the same reason the student's are:
 * they print onto a fixed run of ruled lines, and text past the last line
 * would be dropped silently.
 *
 * The two signatory names are what print beside "OJT/INTERNSHIP Coordinator
 * Signature:" and "Reviewed by:" (and the coordinator's in Section A). Both
 * optional: left blank, the names on record print instead. Measured against
 * their printed column like everything else, in the bold capitals they print
 * in.
 */
class SaveExitInterviewReviewRequest extends FormRequest
{
    /** A cheap bound before the width check; no name that fits its printed
     *  column is anywhere near it. */
    public const NAME_MAX = 120;

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
            'coordinator_name' => ['nullable', 'string', 'max:'.self::NAME_MAX],
            'dean_name' => ['nullable', 'string', 'max:'.self::NAME_MAX],
        ];
    }

    /**
     * Same width measurement as the student's own answers — both print onto a
     * fixed run of ruled lines, so both must be refused rather than truncated.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $interview = $this->route('exitInterview');
            $layout = ExitInterviewFormLayout::for(
                $interview instanceof StudentExitInterview ? $interview->form() : ExitInterviewForms::get(null)
            );

            // Both fields are laid onto printed rules exactly like a student's
            // answer, so both go through the same width check rather than a
            // hand-written one that could drift from the layout.
            foreach (['pending_detail', 'remarks'] as $field) {
                if ($layout->fits($field, $this->input($field))) {
                    continue;
                }

                $lines = count($layout->rules()[$field]);

                $validator->errors()->add(
                    $field,
                    "This is longer than the {$lines} lines the printed form leaves for it. Please shorten it."
                );
            }

            foreach (['coordinator_name', 'dean_name'] as $field) {
                if ($validator->errors()->has($field) || ExitInterviewFormLayout::signatoryNameFits($this->input($field))) {
                    continue;
                }

                $validator->errors()->add(
                    $field,
                    'This name is too long for the line the printed form leaves for it. Please shorten it.'
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
            'coordinator_name' => 'Coordinator name',
            'dean_name' => 'Dean name',
        ];
    }
}
