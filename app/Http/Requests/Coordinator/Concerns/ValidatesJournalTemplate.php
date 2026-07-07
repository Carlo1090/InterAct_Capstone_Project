<?php

namespace App\Http\Requests\Coordinator\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesJournalTemplate
{
    protected function journalTemplateRules(): array
    {
        return [
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id'), Rule::in($this->coordinatorProgramIds())],
            'name' => ['required', 'string', 'max:150'],
            'word_limit' => ['required', 'integer', 'between:50,2000'],
            'is_active' => ['sometimes', 'boolean'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'sections.*.label' => ['required', 'string', 'max:100'],
            'sections.*.prompt' => ['nullable', 'string', 'max:255'],
            'sections.*.required' => ['required', 'boolean'],
            'sections.*.sipp' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $sections = $this->input('sections', []);

            if (! is_array($sections)) {
                return;
            }

            $keys = collect($sections)->pluck('key')->filter();

            if ($keys->count() !== $keys->unique()->count()) {
                $validator->errors()->add('sections', 'Section keys must be unique.');
            }

            $hasRequired = collect($sections)->contains(fn ($section) => ! empty($section['required']));

            if (! $hasRequired) {
                $validator->errors()->add('sections', 'At least one section must be required.');
            }
        });
    }

    /**
     * @return array<int, int>
     */
    protected function coordinatorProgramIds(): array
    {
        return $this->user()->batchesCoordinated()->distinct()->pluck('program_id')->all();
    }
}
