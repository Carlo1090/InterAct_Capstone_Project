<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'system_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'institution_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'institution_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'system_email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ];
    }
}
