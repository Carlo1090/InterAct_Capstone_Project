<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level 'role:admin' middleware already restricts this, but
        // authorize() is kept explicit in case this request class is ever
        // reused on a route without that middleware.
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', 'in:student,supervisor,coordinator,admin'],
            'program_id' => ['nullable', 'exists:programs,id'],
            'is_active' => ['boolean'],
        ];
    }
}
