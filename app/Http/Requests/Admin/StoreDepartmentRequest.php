<?php

namespace App\Http\Requests\Admin;

use App\Support\ExitInterview\ExitInterviewForms;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', 'unique:departments,name'],
            'code' => ['required', 'string', 'max:20', 'unique:departments,code'],
            'dean_name' => ['nullable', 'string', 'max:150'],
            // Which of the hardcoded exit interview forms this department's
            // students fill in. Required on create: a department is never
            // left without one, and the admin chooses rather than inheriting
            // a default they never saw.
            'exit_interview_form' => ['required', 'string', Rule::in(ExitInterviewForms::keys())],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['exit_interview_form' => 'Exit Interview Form'];
    }
}
