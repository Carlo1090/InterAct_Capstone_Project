<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The coordinator's half of "return this journal for revision".
 *
 * Deliberately a separate class from the supervisor's identically-shaped
 * request rather than a relaxed shared one: the only difference IS the role
 * gate, and widening the supervisor's request to accept coordinators would let
 * a coordinator post to the supervisor's own routes. The rule and the message
 * are kept verbatim so the student reads the same thing either way.
 */
class ReturnWeeklyLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        return [
            // Returning a log must explain what the student needs to fix.
            'supervisor_comment' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'supervisor_comment.required' => 'Explain what the student needs to fix before returning this log.',
        ];
    }
}
