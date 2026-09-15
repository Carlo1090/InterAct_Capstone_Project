<?php

namespace App\Http\Requests\Student;

use App\Models\BatchStudent;
use App\Models\StudentExitInterview;
use App\Support\ExitInterview\ExitInterviewForm;
use App\Support\ExitInterview\ExitInterviewForms;
use App\Support\ExitInterviewFormLayout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Save (or submit) the student's exit interview.
 *
 * The rules are BUILT FROM THE FORM the student is answering — resolved the
 * same way the controller resolves it (their interview's own snapshot, else
 * their batch's department's assignment) — so a CAST student is held to the
 * CAST questions and a CABM student to the CABM ones by the same code.
 *
 * Two rules carry the weight here:
 *
 * 1. Every free-text answer must FIT its printed rules. The form gives each
 *    question five ruled lines and the PDF wraps onto exactly those, so an
 *    unbounded answer would be silently truncated off the bottom of the box.
 *    Refusing the save is honest; dropping a student's words without telling
 *    them is not.
 *
 * 2. A SUBMIT requires every question answered, a draft requires nothing.
 *    The whole form is one interview: a half-finished one handed to a
 *    coordinator is worse than none, but a student must be able to stop
 *    typing and come back.
 */
class StoreExitInterviewRequest extends FormRequest
{
    private ?ExitInterviewForm $form = null;

    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        $submitting = $this->boolean('submit');
        $form = $this->form();

        $rules = [
            'submit' => ['sometimes', 'boolean'],

            'student_info' => ['required', 'array'],
            'student_info.department_position' => [$submitting ? 'required' : 'nullable', 'string', 'max:120'],
            'student_info.total_hours' => ['nullable', 'string', 'max:20'],
            'student_info.date_of_interview' => [$submitting ? 'required' : 'nullable', 'date_format:Y-m-d'],

            'responses' => ['required', 'array'],
        ];

        foreach ($form->questions() as $question) {
            if ($question['type'] === ExitInterviewForm::TYPE_SCALE) {
                $rules['responses.'.$question['key']] = [
                    $submitting ? 'required' : 'nullable',
                    Rule::in(array_keys($question['options'])),
                ];

                continue;
            }

            $rules['responses.'.$question['key']] = [
                $submitting ? 'required' : 'nullable',
                'string',
                // A cheap gate only; the binding check is the width
                // measurement in withValidator() below.
                'max:'.ExitInterviewFormLayout::HARD_CHAR_CAP,
            ];

            if ($question['type'] === ExitInterviewForm::TYPE_YES_NO_TEXT) {
                $rules['responses.'.$question['choice']] = [
                    $submitting ? 'required' : 'nullable',
                    Rule::in(['yes', 'no']),
                ];
            }
        }

        return $rules;
    }

    /**
     * The real length guarantee: does each answer actually FIT the rules
     * printed for it? A character count cannot do this job — the same 450
     * characters fit five lines comfortably in ordinary prose and overrun by
     * a line and a half in capitals (ExitInterviewFormLayout::CHARS_PER_LINE
     * has the measurements). The renderer silently drops whatever will not
     * fit, because a PDF cannot refuse; so the refusal belongs here, where the
     * student can still do something about it.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $responses = $this->input('responses');

            if (! is_array($responses)) {
                return;
            }

            $form = $this->form();
            $layout = ExitInterviewFormLayout::for($form);

            foreach ($form->questions() as $question) {
                if ($question['type'] === ExitInterviewForm::TYPE_SCALE) {
                    continue;
                }

                $key = $question['key'];
                $answer = $responses[$key] ?? null;

                if (is_string($answer) && ! $layout->fits($key, $answer)) {
                    $lines = count($layout->rules()[$key]);

                    $validator->errors()->add(
                        'responses.'.$key,
                        "This answer is longer than the {$lines} lines the printed form gives question ".$form->numberOf($key).'. Please shorten it.'
                    );
                }
            }
        });
    }

    public function attributes(): array
    {
        $labels = [
            'student_info.department_position' => 'Department/Position Assigned',
            'student_info.total_hours' => 'Total Hours Completed',
            'student_info.date_of_interview' => 'Date of Interview',
        ];

        foreach ($this->form()->questions() as $question) {
            $labels['responses.'.$question['key']] = 'Question '.$question['n'];

            if ($question['type'] === ExitInterviewForm::TYPE_YES_NO_TEXT) {
                $labels['responses.'.$question['choice']] = 'Question '.$question['n'].' (Yes/No)';
            }
        }

        return $labels;
    }

    protected function prepareForValidation(): void
    {
        // An untouched textarea arrives as '', which would fail `required` on
        // submit with "must be a string" rather than "is required"; and on a
        // draft it should store as absent, not as an empty string.
        $responses = $this->input('responses');

        if (is_array($responses)) {
            $this->merge([
                'responses' => array_map(
                    fn ($value) => is_string($value) && trim($value) === '' ? null : $value,
                    $responses
                ),
            ]);
        }
    }

    /**
     * The form the student is answering. An interview already started keeps
     * its own snapshot; otherwise it is the batch's department's current
     * choice — the identical resolution the controller makes when it writes
     * the row, so the two cannot validate against one form and store another.
     */
    public function form(): ExitInterviewForm
    {
        if ($this->form !== null) {
            return $this->form;
        }

        $studentId = $this->user()?->id;

        $enrollment = $studentId
            ? BatchStudent::with('batch.program.department')
                ->where('student_id', $studentId)
                ->whereIn('status', ['active', 'completed'])
                ->latest('enrolled_at')
                ->first()
            : null;

        $interview = $enrollment
            ? StudentExitInterview::where('student_id', $studentId)->where('batch_id', $enrollment->batch_id)->first()
            : null;

        return $this->form = ExitInterviewForms::forInterview($interview, $enrollment);
    }
}
