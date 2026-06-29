<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', 'unique:departments,name'],
            'code' => ['required', 'string', 'max:20', 'unique:departments,code'],
            'is_active' => ['boolean'],
        ];
    }
}
