<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:200'],
            // Uniqueness is scoped to the department, mirroring the table's own
            // UNIQUE(department_id, code) — a code is unique WITHIN a department,
            // never globally, because departments are independent top-level units
            // (see PROJECT.md Domain Facts). Validating globally would refuse a
            // perfectly legal code and surface as a mystery 422; not validating
            // at all would surface the index violation as a 500.
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('programs', 'code')->where('department_id', $this->input('department_id')),
            ],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'department_id' => 'department',
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This department already has a program with that code.',
        ];
    }
}
