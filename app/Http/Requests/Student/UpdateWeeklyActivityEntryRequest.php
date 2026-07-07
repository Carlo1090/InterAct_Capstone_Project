<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWeeklyActivityEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'inclusive_date_start' => ['sometimes', 'date'],
            'inclusive_date_end' => ['sometimes', 'date', 'after_or_equal:inclusive_date_start'],
            'activities' => ['sometimes', 'string'],
            'documents_records' => ['sometimes', 'nullable', 'string'],
            'objectives' => ['sometimes', 'nullable', 'string'],
            'supervisor_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'supervisor_position' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
