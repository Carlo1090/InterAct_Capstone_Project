<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            // Coordinators may only create students or supervisors — never
            // another coordinator or an admin.
            'role' => ['required', Rule::in(['student', 'supervisor'])],
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'student_id_number' => ['nullable', 'string', 'max:30', 'unique:users,student_id_number'],
        ];
    }
}
