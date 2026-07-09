<?php

namespace App\Http\Requests\Student;

use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreJournalEntryRequest extends FormRequest
{
    use ResolvesStudentEnrollment;

    /**
     * Max characters allowed in a SIPP-flagged section (issues_concerns,
     * solutions, recommendations), so over-long text never reaches the
     * coordinator's report.
     */
    public const SIPP_CHAR_LIMIT = 300;

    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'status' => ['required', 'in:draft,submitted'],
            'content' => ['required', 'array'],
            'content.*' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $enrollment = $this->activeEnrollment($this->user()->id);

            if (! $enrollment) {
                return;
            }

            $template = $enrollment->batch->journalTemplate;
            $sections = $template?->sections ?? [];
            $wordLimit = $template?->word_limit ?? 500;
            $content = $this->input('content', []);

            if (! is_array($content)) {
                return;
            }

            $allowedKeys = collect($sections)->pluck('key')->filter()->all();

            foreach (array_keys($content) as $key) {
                if (! in_array($key, $allowedKeys, true)) {
                    $validator->errors()->add("content.{$key}", 'Unknown journal section.');
                }
            }

            foreach ($sections as $section) {
                if (! empty($section['required']) && trim((string) ($content[$section['key']] ?? '')) === '') {
                    $validator->errors()->add("content.{$section['key']}", "The {$section['label']} field is required.");
                }

                if (! empty($section['sipp'])) {
                    $length = mb_strlen((string) ($content[$section['key']] ?? ''));

                    if ($length > self::SIPP_CHAR_LIMIT) {
                        $validator->errors()->add(
                            "content.{$section['key']}",
                            "The {$section['label']} field must not exceed ".self::SIPP_CHAR_LIMIT.' characters.'
                        );
                    }
                }
            }

            $wordCount = collect($content)->sum(
                fn ($value) => is_string($value) && trim($value) !== '' ? str_word_count($value) : 0
            );

            if ($wordCount > $wordLimit) {
                $validator->errors()->add('content', "This entry exceeds the {$wordLimit}-word limit ({$wordCount} words).");
            }
        });
    }
}
