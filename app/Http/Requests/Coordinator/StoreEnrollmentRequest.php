<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        // Any batch in the coordinator's department, matching info-sheet Accept
        // and the batch roster (see User::placeableBatchIds()).
        $batchIds = $this->user()->placeableBatchIds()->all();

        return [
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id'), Rule::in($batchIds)],
            'student_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', 'student'),
                Rule::unique('batch_students', 'student_id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'assigned_division' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.unique' => 'This student already has an active OJT enrollment.',
            'batch_id.in' => 'The selected batch is outside your department.',
        ];
    }
}
