<?php

namespace App\Http\Requests\Coordinator\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesJournalTemplate
{
    /**
     * SIPP (Annex C) is a fixed trio, identified by section key. A section may
     * only be flagged sipp=true if its key is one of these three.
     */
    public const SIPP_KEYS = ['issues_concerns', 'solutions', 'recommendations'];

    protected function journalTemplateRules(): array
    {
        return [
            'program_ids' => ['required', 'array', 'min:1'],
            'program_ids.*' => ['integer', Rule::exists('programs', 'id'), Rule::in($this->coordinatorProgramIds())],
            'name' => ['required', 'string', 'max:150'],
            'char_limit' => ['required', 'integer', 'between:100,10000'],
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

            // SIPP (Annex C) is a fixed trio — a sipp=true section must be one of
            // issues_concerns / solutions / recommendations (never a rogue field).
            foreach ($sections as $index => $section) {
                if (! empty($section['sipp']) && ! in_array($section['key'] ?? null, self::SIPP_KEYS, true)) {
                    $validator->errors()->add(
                        "sections.{$index}.sipp",
                        'Only the SIPP trio (issues_concerns, solutions, recommendations) may be marked as SIPP (Annex C) fields.'
                    );
                }
            }

            $this->guardProgramConflicts($validator);
        });
    }

    /**
     * A program can belong to at most one template. Reject any selected program
     * already claimed by a DIFFERENT template (naming the conflict), so the
     * unique(program_id) constraint never surfaces as a 500.
     */
    protected function guardProgramConflicts(Validator $validator): void
    {
        $programIds = $this->input('program_ids', []);

        if (! is_array($programIds) || empty($programIds)) {
            return;
        }

        $currentTemplateId = optional($this->route('journalTemplate'))->id;

        $conflicts = DB::table('journal_template_program')
            ->join('programs', 'programs.id', '=', 'journal_template_program.program_id')
            ->whereIn('journal_template_program.program_id', $programIds)
            ->when($currentTemplateId, fn ($query) => $query->where('journal_template_program.journal_template_id', '!=', $currentTemplateId))
            ->pluck('programs.code', 'programs.id');

        if ($conflicts->isNotEmpty()) {
            $validator->errors()->add(
                'program_ids',
                'These programs are already covered by another template: '.$conflicts->implode(', ').'.'
            );
        }
    }

    /**
     * @return array<int, int>
     */
    protected function coordinatorProgramIds(): array
    {
        return $this->user()->coordinatorProgramIds()->all();
    }
}
