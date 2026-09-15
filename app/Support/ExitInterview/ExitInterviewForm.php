<?php

namespace App\Support\ExitInterview;

/**
 * ONE department's exit interview form, as data: which questions it asks, in
 * which sections, with which answer type, and the few masthead lines that
 * name it on paper.
 *
 * Every form in the app shares the SAME template — the CABM "Internship
 * Program Student Exit Interview Form"'s Section A (student information),
 * its draft → submitted → reviewed lifecycle, its coordinator block at the
 * foot of the last page, and its measured page geometry
 * (App\Support\ExitInterviewFormLayout). What differs between departments is
 * the question set and the masthead, so that is all this object holds.
 * Everything that reads a form — the Form Request, the two controllers, the
 * PDF, the Summary Report, the SPA and the mobile app — reads it from here
 * rather than carrying its own transcription of the paper.
 *
 * The definitions themselves are hardcoded, in Forms/*.php, and are chosen
 * per department by the admin (departments.exit_interview_form).
 */
final class ExitInterviewForm
{
    public const TYPE_TEXT = 'text';

    /** A printed ☐ Yes ☐ No pair beside the question, then the answer lines. */
    public const TYPE_YES_NO_TEXT = 'yes_no_text';

    /** A row of printed boxes and nothing else — no answer lines. */
    public const TYPE_SCALE = 'scale';

    /**
     * @param  array<int, string>  $titleLines  The form's title in the masthead, one array entry per printed line (at most two).
     * @param  array<int, string>  $preamble  Standing copy under the masthead, one entry per line; may be empty.
     * @param  array<int, array{heading: string, questions: array<int, array<string, mixed>>}>  $sections
     * @param  string|null  $subtitle  A line printed under a ONE-line title, in the second title slot — the CAST paper's "On-the-Job Training (OJT) Program".
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $collegeLine,
        public readonly array $titleLines,
        public readonly array $preamble,
        public readonly string $studentInfoHeading,
        public readonly string $reference,
        public readonly array $sections,
        public readonly ?string $subtitle = null,
    ) {}

    /**
     * Every question, flattened and normalised: `type` is always present,
     * `choice`/`label` only on a yes_no_text question, `options` only on a
     * scale question.
     *
     * @return array<int, array<string, mixed>>
     */
    public function questions(): array
    {
        $questions = [];

        foreach ($this->sections as $section) {
            foreach ($section['questions'] as $question) {
                $questions[] = self::normalise($question) + ['section' => $section['heading']];
            }
        }

        return $questions;
    }

    /**
     * The sections with every question normalised — the shape the layout
     * iterates and the API serves.
     *
     * @return array<int, array{heading: string, questions: array<int, array<string, mixed>>}>
     */
    public function normalisedSections(): array
    {
        return array_map(fn (array $section) => [
            'heading' => $section['heading'],
            'questions' => array_map([self::class, 'normalise'], $section['questions']),
        ], $this->sections);
    }

    /**
     * @return array<int, string>
     */
    public function questionKeys(): array
    {
        return array_column($this->questions(), 'key');
    }

    /**
     * The `q2_choice`-style keys of every question carrying a Yes/No pair.
     *
     * @return array<int, string>
     */
    public function choiceKeys(): array
    {
        return array_values(array_filter(array_column($this->questions(), 'choice')));
    }

    /**
     * @return array<string, array<string, string>> scale question key => its options
     */
    public function scales(): array
    {
        $scales = [];

        foreach ($this->questions() as $question) {
            if ($question['type'] === self::TYPE_SCALE) {
                $scales[$question['key']] = $question['options'];
            }
        }

        return $scales;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function question(string $key): ?array
    {
        foreach ($this->questions() as $question) {
            if ($question['key'] === $key) {
                return $question;
            }
        }

        return null;
    }

    /**
     * The printed number of a question, for a validation message — the paper
     * form's own numbering, which is not necessarily contiguous.
     */
    public function numberOf(string $key): string
    {
        return (string) ($this->question($key)['n'] ?? ltrim($key, 'q'));
    }

    /**
     * What the clients receive. Deliberately everything a form needs to be
     * RENDERED and nothing about how it is laid out on paper.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'college_line' => $this->collegeLine,
            'title' => implode(' ', $this->titleLines),
            'subtitle' => $this->subtitle,
            'student_info_heading' => $this->studentInfoHeading,
            'sections' => $this->normalisedSections(),
            'question_count' => count($this->questions()),
        ];
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    private static function normalise(array $question): array
    {
        $type = $question['type'] ?? (isset($question['choice']) ? self::TYPE_YES_NO_TEXT : self::TYPE_TEXT);

        $normalised = [
            'n' => $question['n'],
            'key' => $question['key'],
            'text' => $question['text'],
            'type' => $type,
        ];

        if ($type === self::TYPE_YES_NO_TEXT) {
            $normalised['choice'] = $question['choice'];
            $normalised['label'] = $question['label'] ?? null;
        }

        if ($type === self::TYPE_SCALE) {
            $normalised['options'] = $question['options'];
        }

        return $normalised;
    }
}
