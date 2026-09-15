<?php

namespace Tests\Unit\Support;

use App\Support\ExitInterview\ExitInterviewForm;
use App\Support\ExitInterview\ExitInterviewForms;
use App\Support\ExitInterviewFormLayout as Layout;
use Tests\TestCase;

/**
 * The exit interview form's computed layout.
 *
 * The reference PDF's page box, ruled column, 13.2pt pitch, type and wording
 * are kept; its SPACING is not, because Word left it irregular. These tests
 * pin the regularity — each one describes an inconsistency the reference
 * actually has, so a failure means the form has drifted back toward it.
 *
 * Since 2026-09-15 the layout is one instance per FORM (Layout::for()), and
 * the questions paginate automatically. The CABM cases below must keep
 * producing exactly the two pages that were hand-stated before; the CAST
 * cases pin what the paginator does with a form it was never measured for.
 */
class ExitInterviewFormLayoutTest extends TestCase
{
    private function cabm(): Layout
    {
        return Layout::for('cabm');
    }

    private function cast(): Layout
    {
        return Layout::for('cast');
    }

    public function test_the_page_is_philippine_long_bond(): void
    {
        // 8.5" x 13" at 72pt/inch. NOT Letter (612x792) and not A4.
        $this->assertSame(612.0, Layout::PAGE_WIDTH);
        $this->assertSame(936.0, Layout::PAGE_HEIGHT);
    }

    /**
     * The reference pitches its answer rules at 13.15 / 13.20 / 13.25 / 13.40
     * / 13.45 / 13.65pt in different places. There is one pitch here — on
     * every form.
     */
    public function test_every_answer_rule_is_on_the_same_pitch(): void
    {
        foreach (ExitInterviewForms::keys() as $key) {
            $pitches = [];

            foreach (Layout::for($key)->rules() as $lines) {
                for ($i = 1; $i < count($lines); $i++) {
                    if ($lines[$i][0] === $lines[$i - 1][0]) {
                        $pitches[] = round($lines[$i][1] - $lines[$i - 1][1], 2);
                    }
                }
            }

            $this->assertNotEmpty($pitches, $key);
            $this->assertSame([Layout::LINE], array_values(array_unique($pitches)), $key);
        }
    }

    /**
     * The reference gives question 7 four answer lines where every other
     * question gets five, and SPLITS them across the page break — three at the
     * foot of page 1, one at the top of page 2, so one answer runs across two
     * sheets. Every question now gets the same box, on one page — and the
     * paginator guarantees the same for a form more than twice as long.
     */
    public function test_every_question_gets_the_same_answer_box_on_a_single_page(): void
    {
        foreach (ExitInterviewForms::all() as $form) {
            $rules = Layout::for($form)->rules();

            foreach ($form->questions() as $question) {
                if ($question['type'] === ExitInterviewForm::TYPE_SCALE) {
                    $this->assertArrayNotHasKey($question['key'], $rules, 'a rating question has no answer lines');

                    continue;
                }

                $lines = $rules[$question['key']];

                $this->assertCount(Layout::ANSWER_LINES, $lines, "{$form->key} question {$question['n']} has a different sized answer box");
                $this->assertCount(1, array_unique(array_column($lines, 0)), "{$form->key} question {$question['n']}'s answer is split across pages");
            }
        }
    }

    /**
     * The CABM form's pages are the ones that were measured and hand-stated
     * before pagination was computed: the break falls after question 7, and
     * the coordinator's block fits at the foot of page 2. The paginator must
     * reproduce that exactly, or the measured facsimile has moved.
     */
    public function test_the_cabm_form_still_breaks_after_question_seven_onto_two_pages(): void
    {
        $doc = $this->cabm()->document();
        $rules = $this->cabm()->rules();

        $this->assertSame(2, $doc['pages']);
        $this->assertSame(1, $rules['q7'][0][0]);
        $this->assertSame(2, $rules['q8'][0][0]);
        $this->assertSame(2, $rules['remarks'][0][0]);
        $this->assertEqualsWithDelta(868.85, $doc['bottom'][1], 0.01);
        $this->assertEqualsWithDelta(871.37, $doc['bottom'][2], 0.01);
    }

    /**
     * The CAST form was never measured — its pages exist only because the
     * paginator made them. Every page must still clear the folio, and a
     * section heading must never be the last thing on a page.
     */
    public function test_the_cast_form_paginates_without_orphaning_a_heading(): void
    {
        $doc = $this->cast()->document();

        $this->assertGreaterThan(2, $doc['pages']);

        $headings = array_filter(
            $doc['texts'],
            fn (array $run) => $run['font'] === 'bold' && preg_match('/^[A-F]\. /', $run['text']) === 1
        );

        $this->assertCount(6, $headings, 'A through F');

        foreach ($headings as $heading) {
            // Something must follow the heading on its own page: at least one
            // answer rule (or, for a rating question, a box) lower down.
            $followers = array_filter(
                array_merge($doc['rules'], $doc['boxes']),
                fn (array $item) => $item['page'] === $heading['page'] && $item['y'] > $heading['baseline']
            );

            $this->assertNotEmpty($followers, $heading['text'].' is orphaned at the foot of page '.$heading['page']);
        }
    }

    /**
     * Question 33 is the one rating question on either form: five boxes in a
     * row, on their own line under the question, each with a mark position,
     * and NO answer rules.
     */
    public function test_a_rating_question_is_a_row_of_boxes_with_no_answer_lines(): void
    {
        $doc = $this->cast()->document();

        $marks = array_filter(array_keys($doc['marks']), fn (string $key) => str_starts_with($key, 'q33:'));

        $this->assertSame(
            ['q33:excellent', 'q33:very_good', 'q33:good', 'q33:fair', 'q33:poor'],
            array_values($marks)
        );

        $this->assertArrayNotHasKey('q33', $doc['fields']);

        // All five boxes share one baseline and sit on the question's own page.
        $ys = array_unique(array_map(fn (string $key) => round($doc['marks'][$key]['baseline'], 2), array_values($marks)));
        $this->assertCount(1, $ys);

        // And the row is wide enough to need spacing but fits the column.
        $xs = array_map(fn (string $key) => $doc['marks'][$key]['x'], array_values($marks));
        $this->assertSame($xs, array_values(array_unique($xs)));
        $this->assertLessThan(Layout::RULE_RIGHT, max($xs));
    }

    /**
     * The reference's ☐ Yes ☐ No pairs sit at four different x positions
     * (378.12, 450.12, 324.00, 466.80), each simply trailing however long its
     * question happened to be. They now share one column.
     */
    public function test_every_yes_no_pair_sits_in_the_same_column(): void
    {
        $boxes = array_values(array_filter(
            $this->cabm()->document()['boxes'],
            fn (array $box) => $box['x'] > Layout::TEXT_X
        ));

        $this->assertCount(8, $boxes, 'four Yes/No questions, two boxes each');
        $this->assertCount(2, array_unique(array_column($boxes, 'x')), 'the Yes and No columns must each be a single x');
    }

    /**
     * The reference uses one left margin on page 1 and another 18pt further in
     * on page 2, for the same elements.
     */
    public function test_both_pages_share_one_left_grid(): void
    {
        foreach ($this->cabm()->document()['texts'] as $run) {
            if ($run['align'] === 'center') {
                continue;
            }

            $this->assertGreaterThanOrEqual(
                Layout::MARGIN_LEFT,
                $run['x'],
                'nothing may set to the left of the ruled column: '.$run['text']
            );
        }

        // Section headings are flush with the rules on BOTH pages.
        $headings = array_filter(
            $this->cabm()->document()['texts'],
            fn (array $run) => $run['font'] === 'bold' && preg_match('/^[A-G]\. /', $run['text']) === 1
        );

        $this->assertCount(7, $headings, 'A through G');

        foreach ($headings as $heading) {
            $this->assertSame(Layout::MARGIN_LEFT, $heading['x'], $heading['text'].' is off the grid');
        }
    }

    /**
     * Nothing may reach the folio: an answer printing across the page number
     * is exactly what the reference's own overrun would have produced. On
     * every page of every form.
     */
    public function test_no_page_of_any_form_runs_into_the_folio(): void
    {
        foreach (ExitInterviewForms::keys() as $key) {
            $doc = Layout::for($key)->document();

            $this->assertCount($doc['pages'], $doc['bottom']);

            foreach ($doc['bottom'] as $page => $bottom) {
                $this->assertLessThan(
                    Layout::FOLIO_BASELINE - 20.0,
                    $bottom,
                    "{$key} page {$page}'s lowest ink is too close to the page number"
                );
            }
        }
    }

    /**
     * Only question 4 is genuinely too long for its column; everything else
     * must sit on one line. A second line elsewhere means a widow — question
     * 11 in particular clears its ☐ Yes ☐ No pair by only a few points.
     */
    public function test_only_the_one_over_long_question_wraps(): void
    {
        $textRuns = array_filter(
            $this->cabm()->document()['texts'],
            fn (array $run) => $run['x'] === Layout::TEXT_X
        );

        $this->assertCount(15, $textRuns, '14 questions plus question 4\'s genuine second line');
    }

    /**
     * The masthead's college line and title are the form's own; the rest of
     * the masthead is the college's and identical on both.
     */
    public function test_the_masthead_names_the_form_it_belongs_to(): void
    {
        $centred = fn (Layout $layout) => array_column(
            array_filter($layout->document()['texts'], fn (array $run) => $run['align'] === 'center'),
            'text'
        );

        $this->assertSame(
            ['Mater Dei College', 'Tubigon, Bohol', 'College of Accountancy, Business and Management (CABM)', 'INTERNSHIP PROGRAM STUDENT EXIT', 'INTERVIEW FORM'],
            $centred($this->cabm())
        );

        // The CAST paper sets its title on one line with a subtitle under it;
        // the subtitle takes the template's second title slot.
        $this->assertSame(
            ['Mater Dei College', 'Tubigon, Bohol', 'College of Arts, Sciences, and Technology (CAST)', 'EXIT INTERVIEW QUESTIONNAIRE FOR ON-THE-JOB (OJT) STUDENTS', 'On-the-Job Training (OJT) Program'],
            $centred($this->cast())
        );
    }

    public function test_an_answer_is_laid_onto_its_own_rules_in_order(): void
    {
        $text = str_repeat('The intern reconciled the branch cash position each afternoon. ', 4);

        $placed = $this->cabm()->place(['q1' => $text]);
        $rules = $this->cabm()->rules()['q1'];

        $this->assertNotEmpty($placed);

        foreach ($placed as $index => $line) {
            [$page, $ruleY] = $rules[$index];

            $this->assertSame($page, $line['page']);
            $this->assertEqualsWithDelta($ruleY - Layout::BASELINE_LIFT, $line['baseline'], 0.001);
            // Every line must actually fit the rule it sits on.
            $this->assertLessThanOrEqual($line['width'], Layout::textWidth($line['text']));
        }
    }

    /**
     * "Please explain:" is printed ON the first answer rule, so the answer
     * after it starts further in. The reference instead orphans that label
     * onto a line of its own 18pt to the LEFT of its own question.
     */
    public function test_a_labelled_answer_starts_clear_of_its_printed_label(): void
    {
        foreach (['q2', 'q7', 'q10', 'pending_detail'] as $key) {
            $lines = $this->cabm()->document()['fields'][$key];

            $this->assertGreaterThan(Layout::MARGIN_LEFT, $lines[0]['x'], "{$key}'s first line runs under its label");
            $this->assertSame(Layout::MARGIN_LEFT, $lines[1]['x'], "{$key}'s second line should return to the margin");
        }

        // Question 11 carries a Yes/No pair but no explain label, so its first
        // line starts at the margin like any other answer.
        $this->assertSame(Layout::MARGIN_LEFT, $this->cabm()->document()['fields']['q11'][0]['x']);
    }

    public function test_an_empty_answer_places_nothing(): void
    {
        $this->assertSame([], $this->cabm()->place(['q1' => '   ', 'q2' => null]));
    }

    public function test_a_word_too_wide_for_a_line_is_broken_rather_than_left_to_overhang(): void
    {
        $width = Layout::RULE_RIGHT - Layout::MARGIN_LEFT;
        $lines = Layout::wrap(str_repeat('x', 400), [$width, $width]);

        $this->assertGreaterThan(1, count($lines));

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual($width, Layout::textWidth($line));
        }
    }

    /**
     * The soft cap is per field, from how many rules that field actually has.
     */
    public function test_the_character_cap_follows_the_printed_line_count(): void
    {
        $this->assertSame(Layout::ANSWER_LINES * Layout::CHARS_PER_LINE, $this->cabm()->charLimitFor('q1'));
        $this->assertSame(Layout::ANSWER_LINES * Layout::CHARS_PER_LINE, $this->cabm()->charLimitFor('q7'));
        $this->assertSame(2 * Layout::CHARS_PER_LINE, $this->cabm()->charLimitFor('pending_detail'));
        $this->assertSame(3 * Layout::CHARS_PER_LINE, $this->cabm()->charLimitFor('remarks'));
        $this->assertSame(Layout::ANSWER_LINES * Layout::CHARS_PER_LINE, $this->cast()->charLimitFor('q37'));
    }

    /**
     * fits() is the real guarantee, and it must NOT be a character count in
     * disguise: the same length of text fits in ordinary prose and overruns in
     * capitals, so a length-based check would either be uselessly tight or let
     * a shouted answer be silently truncated off the bottom of the box.
     */
    public function test_fits_measures_width_rather_than_length(): void
    {
        $prose = trim(str_repeat('The intern reconciled the branch cash position each afternoon. ', 20));
        $prose = trim(mb_substr($prose, 0, $this->cabm()->charLimitFor('q1')));

        $this->assertTrue($this->cabm()->fits('q1', $prose));
        $this->assertSame(
            preg_split('/\s+/u', $prose),
            preg_split('/\s+/u', implode(' ', array_column($this->cabm()->place(['q1' => $prose]), 'text'))),
            'placing an answer that fits must not drop a single word'
        );

        $this->assertFalse($this->cabm()->fits('q1', mb_strtoupper($prose)));
    }

    public function test_fits_is_generous_to_an_empty_or_unknown_field(): void
    {
        $this->assertTrue($this->cabm()->fits('q1', null));
        $this->assertTrue($this->cabm()->fits('q1', '   '));
        $this->assertTrue($this->cabm()->fits('not_a_field', str_repeat('x', 5000)));
        // A rating question has no rules to overrun.
        $this->assertTrue($this->cast()->fits('q33', 'excellent'));
    }
}
