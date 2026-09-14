<?php

namespace App\Http\Requests\Admin;

use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        // `department_id` is deliberately ABSENT from these rules and is not
        // accepted here. Re-parenting a program would hand every batch and intern
        // under it to a different department's coordinators in one silent write —
        // that is a migration of live records, not an edit to a reference row.
        /** @var Program $program */
        $program = $this->route('program');

        return [
            'name' => ['required', 'string', 'max:200'],
            // The code IS editable here, unlike a department's — a deliberate
            // difference, not an oversight. Nothing in app/ ever resolves a
            // program by code (batches, users and templates all key off
            // `program_id`); only the demo seeders do, and they run against a
            // freshly-seeded database. A typo'd code must therefore be fixable,
            // because the alternative is deactivate-and-recreate, which strands
            // every batch already pointing at the original row.
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('programs', 'code')
                    ->where('department_id', $program->department_id)
                    ->ignore($program),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This department already has a program with that code.',
        ];
    }
}
