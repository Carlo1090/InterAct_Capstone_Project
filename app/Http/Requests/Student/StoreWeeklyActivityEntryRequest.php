<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class StoreWeeklyActivityEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'inclusive_date_start' => ['required', 'date'],
            'inclusive_date_end' => ['required', 'date', 'after_or_equal:inclusive_date_start'],
            'activities' => ['required', 'string'],
            'documents_records' => ['nullable', 'string'],
            'objectives' => ['nullable', 'string'],
            'supervisor_name' => ['nullable', 'string', 'max:150'],
            'supervisor_position' => ['nullable', 'string', 'max:100'],
        ];
    }
}
