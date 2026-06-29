<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'role' => ['sometimes', 'in:student,supervisor,coordinator,admin'],
            'program_id' => ['nullable', 'exists:programs,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
