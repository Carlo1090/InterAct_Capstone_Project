<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWeeklyActivityLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'week_start' => ['sometimes', 'date'],
            'week_end' => ['sometimes', 'date', 'after_or_equal:week_start'],
            'area_assigned' => ['sometimes', 'nullable', 'string', 'max:150'],
            'no_of_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999.9'],
        ];
    }
}
