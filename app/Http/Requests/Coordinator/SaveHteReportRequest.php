<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;

class SaveHteReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        return [
            'academic_year' => ['required', 'string', 'max:20'],
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'status' => ['required', 'in:draft,finalized'],

            'signatory_prepared_name' => ['nullable', 'string', 'max:150'],
            'signatory_prepared_title' => ['nullable', 'string', 'max:150'],
            'signatory_certified_name' => ['nullable', 'string', 'max:150'],
            'signatory_certified_title' => ['nullable', 'string', 'max:150'],

            // Overrides for auto-populated candidate rows (keyed by batch_student id).
            'rows' => ['present', 'array'],
            'rows.*.id' => ['required', 'integer'],
            'rows.*.host_establishment' => ['nullable', 'string', 'max:200'],
            'rows.*.student_name' => ['nullable', 'string', 'max:200'],
            'rows.*.program' => ['nullable', 'string', 'max:100'],
            'rows.*.gender' => ['nullable', 'string', 'max:20'],
            'rows.*.duration' => ['nullable', 'string', 'max:100'],
            'rows.*.included' => ['required', 'boolean'],

            // Coordinator-added rows for interns with incomplete source data.
            'manual_rows' => ['sometimes', 'array'],
            'manual_rows.*.id' => ['required', 'string', 'max:50'],
            'manual_rows.*.host_establishment' => ['nullable', 'string', 'max:200'],
            'manual_rows.*.student_name' => ['nullable', 'string', 'max:200'],
            'manual_rows.*.program' => ['nullable', 'string', 'max:100'],
            'manual_rows.*.gender' => ['nullable', 'string', 'max:20'],
            'manual_rows.*.duration' => ['nullable', 'string', 'max:100'],
            'manual_rows.*.included' => ['required', 'boolean'],

            'deleted_ids' => ['sometimes', 'array'],
            'deleted_ids.*' => ['integer'],
        ];
    }
}
