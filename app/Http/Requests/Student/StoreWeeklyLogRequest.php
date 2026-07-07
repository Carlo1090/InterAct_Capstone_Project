<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class StoreWeeklyLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'week_start' => ['required', 'date'],
            'narrative' => ['nullable', 'string'],
            'issues_concerns' => ['nullable', 'string'],
            'solutions' => ['nullable', 'string'],
            'recommendations' => ['nullable', 'string'],
        ];
    }
}
