<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        $programIds = $this->user()->coordinatorProgramIds()->all();

        return [
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id'), Rule::in($programIds)],
            'name' => ['required', 'string', 'max:150'],
            'academic_year' => ['required', 'string', 'max:20'],
            'semester' => ['required', 'string', 'max:30'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'required_hours' => ['required', 'integer', 'min:1'],
            'working_days_per_week' => ['required', 'integer', 'between:1,7'],
            'daily_reminder_time' => ['required', 'date_format:H:i'],
            'journal_template_id' => [
                'nullable',
                'integer',
                // The chosen template must cover the batch's program (pivot is the
                // source of truth now that journal_templates.program_id is gone).
                Rule::exists('journal_template_program', 'journal_template_id')->where('program_id', $this->input('program_id')),
            ],
        ];
    }
}
