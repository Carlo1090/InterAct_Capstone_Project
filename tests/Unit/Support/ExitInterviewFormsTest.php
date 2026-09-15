<?php

namespace Tests\Unit\Support;

use App\Support\ExitInterview\ExitInterviewForm;
use App\Support\ExitInterview\ExitInterviewForms;
use Tests\TestCase;

/**
 * Every hardcoded exit interview form is held to ITS OWN PAPER.
 *
 * tests/Fixtures/exit-interview-references.json is the wording read off the
 * two reference PDFs' content streams (2026-09-15): every section heading,
 * every question's number and text, the Yes/No labels, the rating options,
 * the masthead and the preamble. A form definition that stops matching it
 * fails here — so rewording a question, renumbering one, or moving one
 * between sections is a conscious edit to the fixture as well, never a
 * silent drift from what the department actually prints.
 *
 * What the fixture deliberately does NOT hold a form to is the TEMPLATE:
 * page size, ruled lines, the student-information fields, the signatories.
 * Those are the CABM template's on every form, by direction.
 */
class ExitInterviewFormsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function fixture(string $key): array
    {
        $all = json_decode((string) file_get_contents(base_path('tests/Fixtures/exit-interview-references.json')), true);

        $this->assertArrayHasKey($key, $all, "no reference wording captured for the '{$key}' form");

        return $all[$key];
    }

    public function test_every_form_in_the_catalogue_has_reference_wording_and_a_reference_file(): void
    {
        $all = json_decode((string) file_get_contents(base_path('tests/Fixtures/exit-interview-references.json')), true);

        foreach (ExitInterviewForms::all() as $form) {
            $this->assertArrayHasKey($form->key, $all, "{$form->key} is in the catalogue but has no reference wording in the fixture");
            $this->assertFileExists(base_path('docs/reference/'.$form->reference), "{$form->key}'s reference PDF is not in docs/reference");
        }
    }

    public function test_the_section_headings_are_the_papers_own(): void
    {
        foreach (ExitInterviewForms::all() as $form) {
            $this->assertSame(
                $this->fixture($form->key)['headings'],
                array_column($form->sections, 'heading'),
                "{$form->key}'s section headings differ from the paper"
            );
        }
    }

    public function test_every_question_is_worded_and_numbered_as_the_paper_prints_it(): void
    {
        foreach (ExitInterviewForms::all() as $form) {
            $paper = $this->fixture($form->key)['questions'];
            $ours = [];

            foreach ($form->questions() as $question) {
                $ours[(string) $question['n']] = $question['text'];
            }

            $this->assertSame(
                array_keys($paper),
                array_keys($ours),
                "{$form->key}'s question numbers differ from the paper (a renumbering must be made in the fixture too)"
            );

            foreach ($paper as $n => $text) {
                $this->assertSame($text, $ours[$n], "{$form->key} question {$n} is not worded as the paper prints it");
            }
        }
    }

    public function test_questions_sit_in_the_sections_the_paper_puts_them_in(): void
    {
        foreach (ExitInterviewForms::all() as $form) {
            $paper = $this->fixture($form->key);
            $paperOrder = array_keys($paper['questions']);

            // The paper lists questions in one order under headings in one
            // order; ours, flattened, must follow the same sequence.
            $this->assertSame(
                array_map('intval', $paperOrder),
                array_column($form->questions(), 'n'),
                "{$form->key}'s questions are not in the paper's order"
            );
        }
    }

    public function test_yes_no_labels_and_rating_options_match_the_paper(): void
    {
        foreach (ExitInterviewForms::all() as $form) {
            $paper = $this->fixture($form->key);

            foreach ($form->questions() as $question) {
                if ($question['type'] === ExitInterviewForm::TYPE_YES_NO_TEXT) {
                    $this->assertSame(
                        $paper['labels'][(string) $question['n']] ?? '',
                        (string) ($question['label'] ?? ''),
                        "{$form->key} question {$question['n']}'s explain label differs from the paper"
                    );
                }

                if ($question['type'] === ExitInterviewForm::TYPE_SCALE) {
                    $this->assertSame(
                        $paper['options'],
                        array_values($question['options']),
                        "{$form->key} question {$question['n']}'s rating options differ from the paper"
                    );
                }
            }

            // And no rating exists on a form whose paper has none.
            if ($paper['options'] === null) {
                $this->assertSame([], $form->scales(), "{$form->key} has a rating question its paper does not");
            }
        }
    }

    /**
     * The masthead's college line follows the template's style (title case,
     * with the code in parentheses) but must be the paper's own college; the
     * title and subtitle are the paper's own lines exactly.
     */
    public function test_the_masthead_names_the_papers_own_college_and_title(): void
    {
        foreach (ExitInterviewForms::all() as $form) {
            $masthead = $this->fixture($form->key)['masthead'];

            $this->assertSame(['Mater Dei College', 'Tubigon, Bohol'], array_slice($masthead, 0, 2));

            $this->assertStringStartsWith(
                mb_strtoupper($masthead[2]),
                mb_strtoupper($form->collegeLine),
                "{$form->key}'s college line is not the paper's"
            );

            $titleLines = array_values(array_filter(array_merge($form->titleLines, [$form->subtitle])));

            $this->assertSame(array_slice($masthead, 3), $titleLines, "{$form->key}'s title lines differ from the paper");
        }
    }

    /**
     * The WORDS, not the line break: the paper sets its preamble in condensed
     * Tahoma and breaks after "The information collected"; Helvetica at the
     * substituted size cannot fit that on one line and breaks a word earlier
     * (ExitInterviewFormLayout documents the substitution). Joined, the two
     * must be identical.
     */
    public function test_the_preamble_is_the_papers_own_where_it_has_one(): void
    {
        foreach (ExitInterviewForms::all() as $form) {
            $this->assertSame(
                implode(' ', $this->fixture($form->key)['preamble'] ?? []),
                implode(' ', $form->preamble),
                "{$form->key}'s preamble differs from the paper"
            );
        }
    }
}
