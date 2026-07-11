<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddRosterStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'student')],
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'supervisor_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'supervisor')],
            'assigned_division' => ['nullable', 'string', 'max:150'],
        ];
    }
}
