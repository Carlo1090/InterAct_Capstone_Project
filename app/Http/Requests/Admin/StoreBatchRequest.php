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
            // See Coordinator\StoreBatchRequest — working_days_per_week is
            // derived from the range automatically (BatchObserver); a caller
            // still posting only the legacy count is accepted too.
            'working_days_start' => ['required_with:working_days_end', 'nullable', 'integer', 'between:1,7'],
            'working_days_end' => ['required_with:working_days_start', 'nullable', 'integer', 'between:1,7'],
            'working_days_per_week' => ['required_without_all:working_days_start,working_days_end', 'nullable', 'integer', 'between:1,7'],
            'daily_reminder_time' => ['nullable', 'date_format:H:i:s'],
            'is_active' => ['boolean'],
        ];
    }
}
