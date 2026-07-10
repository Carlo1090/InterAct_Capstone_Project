<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        $program = $this->route('program');

        return [
            'name' => ['required', 'string', 'max:200'],
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('programs', 'code')
                    ->where('department_id', $program->department_id)
                    ->ignore($program),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
