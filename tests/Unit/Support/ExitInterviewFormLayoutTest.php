<?php

namespace Tests\Unit\Support;

use App\Support\ExitInterviewFormLayout as Layout;
use Tests\TestCase;

/**
 * The exit interview form's computed layout.
 *
 * The reference PDF's page box, ruled column, 13.2pt pitch, type and wording
 * are kept; its SPACING is not, because Word left it irregular. These tests
 * pin the regularity — each one describes an inconsistency the reference
 * actually has, so a failure means the form has drifted back toward it.
 */
class ExitInterviewFormLayoutTest extends TestCase
{
    public function test_the_page_is_philippine_long_bond(): void
    {
        // 8.5" x 13" at 72pt/inch. NOT Letter (612x792) and not A4.
        $this->assertSame(612.0, Layout::PAGE_WIDTH);
        $this->assertSame(936.0, Layout::PAGE_HEIGHT);
    }

    /**
     * The reference pitches its answer rules at 13.15 / 13.20 / 13.25 / 13.40
     * / 13.45 / 13.65pt in different places. There is one pitch here.
     */
    public function test_every_answer_rule_is_on_the_same_pitch(): void
    {
        $pitches = [];

        foreach (Layout::rules() as $lines) {
            for ($i = 1; $i < count($lines); $i++) {
                if ($lines[$i][0] === $lines[$i - 1][0]) {
                    $pitches[] = round($lines[$i][1] - $lines[$i - 1][1], 2);
                }
            }
        }

        $this->assertNotEmpty($pitches);
        $this->assertSame([Layout::LINE], array_values(array_unique($pitches)));
    }

    /**
     * The reference gives question 7 four answer lines where every other
     * question gets five, and SPLITS them across the page break — three at the
     * foot of page 1, one at the top of page 2, so one answer runs across two
     * sheets. Every question now gets the same box, on one page.
     */
    public function test_every_question_gets_the_same_answer_box_on_a_single_page(): void
    {
        $rules = Layout::rules();

        foreach (range(1, 14) as $number) {
            $lines = $rules['q'.$number];

            $this->assertCount(Layout::ANSWER_LINES, $lines, "question {$number} has a different sized answer box");
            $this->assertCount(1, array_unique(array_column($lines, 0)), "question {$number}'s answer is split across pages");
        }
    }

    /**
     * The reference's ☐ Yes ☐ No pairs sit at four different x positions
     * (378.12, 450.12, 324.00, 466.80), each simply trailing however long its
     * question happened to be. They now share one column.
     */
    public function test_every_yes_no_pair_sits_in_the_same_column(): void
    {
        $boxes = array_values(array_filter(
            Layout::document()['boxes'],
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
        foreach (Layout::document()['texts'] as $run) {
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
            Layout::document()['texts'],
            fn (array $run) => $run['font'] === 'bold' && preg_match('/^[A-G]\. /', $run['text']) === 1
        );

        $this->assertCount(7, $headings, 'A through G');

        foreach ($headings as $heading) {
            $this->assertSame(Layout::MARGIN_LEFT, $heading['x'], $heading['text'].' is off the grid');
        }
    }

    /**
     * Nothing may reach the folio: an answer printing across the page number
     * is exactly what the reference's own overrun would have produced.
     */
    public function test_neither_page_runs_into_the_folio(): void
    {
        foreach (Layout::document()['bottom'] as $page => $bottom) {
            $this->assertLessThan(
                Layout::FOLIO_BASELINE - 20.0,
                $bottom,
                "page {$page}'s lowest ink is too close to the page number"
            );
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
            Layout::document()['texts'],
            fn (array $run) => $run['x'] === Layout::TEXT_X
        );

        $this->assertCount(15, $textRuns, '14 questions plus question 4\'s genuine second line');
    }

    public function test_an_answer_is_laid_onto_its_own_rules_in_order(): void
    {
        $text = str_repeat('The intern reconciled the branch cash position each afternoon. ', 4);

        $placed = Layout::place(['q1' => $text]);
        $rules = Layout::rules()['q1'];

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
            $lines = Layout::document()['fields'][$key];

            $this->assertGreaterThan(Layout::MARGIN_LEFT, $lines[0]['x'], "{$key}'s first line runs under its label");
            $this->assertSame(Layout::MARGIN_LEFT, $lines[1]['x'], "{$key}'s second line should return to the margin");
        }

        // Question 11 carries a Yes/No pair but no explain label, so its first
        // line starts at the margin like any other answer.
        $this->assertSame(Layout::MARGIN_LEFT, Layout::document()['fields']['q11'][0]['x']);
    }

    public function test_an_empty_answer_places_nothing(): void
    {
        $this->assertSame([], Layout::place(['q1' => '   ', 'q2' => null]));
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
        $this->assertSame(Layout::ANSWER_LINES * Layout::CHARS_PER_LINE, Layout::charLimitFor('q1'));
        $this->assertSame(Layout::ANSWER_LINES * Layout::CHARS_PER_LINE, Layout::charLimitFor('q7'));
        $this->assertSame(2 * Layout::CHARS_PER_LINE, Layout::charLimitFor('pending_detail'));
        $this->assertSame(3 * Layout::CHARS_PER_LINE, Layout::charLimitFor('remarks'));
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
        $prose = trim(mb_substr($prose, 0, Layout::charLimitFor('q1')));

        $this->assertTrue(Layout::fits('q1', $prose));
        $this->assertSame(
            preg_split('/\s+/u', $prose),
            preg_split('/\s+/u', implode(' ', array_column(Layout::place(['q1' => $prose]), 'text'))),
            'placing an answer that fits must not drop a single word'
        );

        $this->assertFalse(Layout::fits('q1', mb_strtoupper($prose)));
    }

    public function test_fits_is_generous_to_an_empty_or_unknown_field(): void
    {
        $this->assertTrue(Layout::fits('q1', null));
        $this->assertTrue(Layout::fits('q1', '   '));
        $this->assertTrue(Layout::fits('not_a_field', str_repeat('x', 5000)));
    }
}
