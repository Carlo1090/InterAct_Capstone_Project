<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'address' => ['sometimes', 'string', 'max:500'],
            'location' => ['sometimes', 'nullable', 'string', 'max:200'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:100'],
            'head_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'department_head' => ['sometimes', 'nullable', 'string', 'max:150'],
            'contact_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
