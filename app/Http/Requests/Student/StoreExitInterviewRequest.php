<?php

namespace App\Http\Requests\Student;

use App\Models\StudentExitInterview;
use App\Support\ExitInterviewFormLayout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Save (or submit) the student's exit interview.
 *
 * Two rules carry the weight here:
 *
 * 1. Every answer is capped at ExitInterviewFormLayout::ANSWER_CHAR_LIMIT.
 *    The printed form gives each question five ruled lines and the PDF wraps
 *    onto exactly those, so an unbounded answer would be silently truncated
 *    off the bottom of the box. Refusing the save is honest; dropping a
 *    student's words without telling them is not.
 *
 * 2. A SUBMIT requires every question answered, a draft requires nothing.
 *    The whole form is one interview: a half-finished one handed to a
 *    coordinator is worse than none, but a student must be able to stop
 *    typing and come back.
 */
class StoreExitInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        $submitting = $this->boolean('submit');

        $rules = [
            'submit' => ['sometimes', 'boolean'],

            'student_info' => ['required', 'array'],
            'student_info.department_position' => [$submitting ? 'required' : 'nullable', 'string', 'max:120'],
            'student_info.total_hours' => ['nullable', 'string', 'max:20'],
            'student_info.date_of_interview' => [$submitting ? 'required' : 'nullable', 'date_format:Y-m-d'],

            'responses' => ['required', 'array'],
        ];

        foreach (StudentExitInterview::QUESTION_KEYS as $key) {
            $rules['responses.'.$key] = [
                $submitting ? 'required' : 'nullable',
                'string',
                // A cheap gate only; the binding check is the width
                // measurement in withValidator() below.
                'max:'.ExitInterviewFormLayout::HARD_CHAR_CAP,
            ];
        }

        foreach (StudentExitInterview::CHOICE_KEYS as $key) {
            $rules['responses.'.$key] = [
                $submitting ? 'required' : 'nullable',
                Rule::in(['yes', 'no']),
            ];
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

            foreach (StudentExitInterview::QUESTION_KEYS as $key) {
                $answer = $responses[$key] ?? null;

                if (is_string($answer) && ! ExitInterviewFormLayout::fits($key, $answer)) {
                    $lines = count(ExitInterviewFormLayout::rules()[$key]);

                    $validator->errors()->add(
                        'responses.'.$key,
                        "This answer is longer than the {$lines} lines the printed form gives question ".ltrim($key, 'q').'. Please shorten it.'
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

        foreach (StudentExitInterview::QUESTION_KEYS as $key) {
            $labels['responses.'.$key] = 'Question '.ltrim($key, 'q');
        }

        foreach (StudentExitInterview::CHOICE_KEYS as $key) {
            $labels['responses.'.$key] = 'Question '.ltrim(str_replace('_choice', '', $key), 'q').' (Yes/No)';
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
}
