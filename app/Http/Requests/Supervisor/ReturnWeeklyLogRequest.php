<?php

namespace App\Http\Requests\Supervisor;

use Illuminate\Foundation\Http\FormRequest;

class ReturnWeeklyLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'supervisor';
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
