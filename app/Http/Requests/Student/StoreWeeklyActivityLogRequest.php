<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class StoreWeeklyActivityLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'week_start' => ['required', 'date'],
            'week_end' => ['required', 'date', 'after_or_equal:week_start'],
            'area_assigned' => ['nullable', 'string', 'max:150'],
            'no_of_hours' => ['nullable', 'numeric', 'min:0', 'max:9999.9'],
        ];
    }
}
