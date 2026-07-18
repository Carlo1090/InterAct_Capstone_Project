<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'address' => ['required', 'string', 'max:500'],
            'location' => ['nullable', 'string', 'max:200'],
            'industry' => ['nullable', 'string', 'max:100'],
            'head_name' => ['nullable', 'string', 'max:150'],
            'head_contact_number' => ['nullable', 'string', 'max:30'],
            'head_email' => ['nullable', 'email', 'max:255'],
            'department_head' => ['nullable', 'string', 'max:150'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
