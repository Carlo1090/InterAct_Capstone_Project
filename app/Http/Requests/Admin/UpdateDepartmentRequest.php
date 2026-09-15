<?php

namespace App\Http\Requests\Admin;

use App\Support\ExitInterview\ExitInterviewForms;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('departments', 'name')->ignore($this->route('department'))],
            'dean_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            // Changing it re-points FUTURE interviews only: every interview
            // already written keeps its own form_key snapshot.
            'exit_interview_form' => ['sometimes', 'string', Rule::in(ExitInterviewForms::keys())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['exit_interview_form' => 'Exit Interview Form'];
    }
}
