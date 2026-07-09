<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;

class SaveAnnualSippReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        return [
            'academic_year' => ['required', 'string', 'max:20'],
            'heading' => ['required', 'string', 'max:200'],
            'status' => ['required', 'in:draft,finalized'],

            'signatory_prepared_name' => ['nullable', 'string', 'max:150'],
            'signatory_prepared_title' => ['nullable', 'string', 'max:150'],
            'signatory_certified_name' => ['nullable', 'string', 'max:150'],
            'signatory_certified_title' => ['nullable', 'string', 'max:150'],

            'rows' => ['present', 'array'],
            'rows.*.id' => ['required', 'integer'],
            'rows.*.issues_concerns' => ['nullable', 'string', 'max:300'],
            'rows.*.solutions' => ['nullable', 'string', 'max:300'],
            'rows.*.recommendations' => ['nullable', 'string', 'max:300'],
            'rows.*.included' => ['required', 'boolean'],

            'deleted_ids' => ['sometimes', 'array'],
            'deleted_ids.*' => ['integer'],
        ];
    }
}
