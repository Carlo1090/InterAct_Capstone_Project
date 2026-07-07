<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class ReorderWeeklyActivityEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'entry_ids' => ['required', 'array'],
            'entry_ids.*' => ['required', 'integer'],
        ];
    }
}
