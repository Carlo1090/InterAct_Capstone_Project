<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'address' => ['required', 'string'],
            'location' => ['nullable', 'string', 'max:200'],
            'industry' => ['nullable', 'string', 'max:100'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'head_name' => ['nullable', 'string', 'max:150'],
            'department_head' => ['nullable', 'string', 'max:150'],
            'is_active' => ['boolean'],
        ];
    }
}
