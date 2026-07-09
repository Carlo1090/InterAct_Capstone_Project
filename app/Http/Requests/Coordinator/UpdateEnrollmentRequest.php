<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user()?->role !== 'coordinator') {
            return false;
        }

        $batchStudent = $this->route('batchStudent');

        return $batchStudent && $batchStudent->batch?->coordinator_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['active', 'completed', 'dropped'])],
            'company_id' => ['sometimes', 'integer', Rule::exists('companies', 'id')],
            'supervisor_id' => ['sometimes', 'integer', Rule::exists('users', 'id')->where('role', 'supervisor')],
            'assigned_division' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }
}
