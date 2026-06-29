<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'program_id' => ['required', 'exists:programs,id'],
            'coordinator_id' => ['required', Rule::exists('users', 'id')->where('role', 'coordinator')],
            'journal_template_id' => ['nullable', 'exists:journal_templates,id'],
            'name' => ['required', 'string', 'max:150'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', 'string', 'max:30'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'required_hours' => ['required', 'integer', 'min:1'],
            'working_days_per_week' => ['required', 'integer', 'min:1', 'max:7'],
            'daily_reminder_time' => ['nullable', 'date_format:H:i:s'],
            'is_active' => ['boolean'],
        ];
    }
}
