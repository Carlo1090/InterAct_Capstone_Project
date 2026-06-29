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
            'department_id' => ['required', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:200'],
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('programs', 'code')->where('department_id', $this->input('department_id')),
            ],
            'is_active' => ['boolean'],
        ];
    }
}
