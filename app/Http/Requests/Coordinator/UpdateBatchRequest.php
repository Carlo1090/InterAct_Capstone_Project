<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user()?->role !== 'coordinator') {
            return false;
        }

        $batch = $this->route('batch');

        return $batch && $batch->coordinator_id === $this->user()->id;
    }

    public function rules(): array
    {
        $batch = $this->route('batch');

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'academic_year' => ['sometimes', 'string', 'max:20'],
            'semester' => ['sometimes', 'string', 'max:30'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after:start_date'],
            'required_hours' => ['sometimes', 'integer', 'min:1'],
            'working_days_per_week' => ['sometimes', 'integer', 'between:1,7'],
            'daily_reminder_time' => ['sometimes', 'date_format:H:i'],
            'is_active' => ['sometimes', 'boolean'],
            'journal_template_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('journal_templates', 'id')->where('program_id', $batch?->program_id),
            ],
        ];
    }
}
