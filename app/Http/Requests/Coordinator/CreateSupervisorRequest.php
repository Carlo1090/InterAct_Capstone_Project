<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;

class CreateSupervisorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            // Email is optional — username + password is the primary,
            // always-available sign-in path (mirrors CreateAccountRequest). A
            // blank username auto-generates from the name (see User::booted()).
            'username' => ['nullable', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'position' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'Username may only contain letters, numbers, dots, dashes and underscores.',
        ];
    }
}
