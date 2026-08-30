<?php

namespace App\Support;

/**
 * The layout of the CABM "Internship Program Student Exit Interview Form" —
 * a COMPUTED document, not a list of hand-placed coordinates.
 *
 * Reference: docs/reference/INTERNSHIP PROGRAM STUDENT EXIT INTERVIEW - BUSINESS.pdf
 *
 * The reference's own geometry was measured off its content stream first (page
 * box, column, rule pitch, type sizes, the two-column Section A, the section
 * order and every word of every question), and all of that is kept. What is
 * NOT kept is its spacing, which is a Word artifact and is irregular in ways
 * that show on the printed page:
 *
 *   - the ruled answer lines are pitched 13.15 / 13.20 / 13.25 / 13.40 / 13.45
 *     / 13.65pt in different places
 *   - the gap from a question to its first answer rule ranges 14.84 - 15.58pt
 *   - the ☐ Yes ☐ No pairs sit at FOUR different x positions (378.12, 450.12,
 *     324.00, 466.80) because each simply follows however long its question
 *     happened to be
 *   - "Please explain:" is orphaned onto its own line 18pt to the LEFT of the
 *     question it belongs to, because Word wrapped it off the end
 *   - page 1's whole body is indented 18pt less than page 2's, so the same
 *     document has two different left margins
 *   - question 7 gets four answer lines where every other question gets five,
 *     and they are SPLIT across the page break (three at the foot of page 1,
 *     one at the top of page 2), so one answer runs across two sheets
 *
 * Everything here is therefore derived from one grid and one vertical rhythm,
 * so the pattern is structural rather than something a future edit has to
 * remember. The page box, the ruled column, the 13.2pt pitch, the type and the
 * wording are the reference's; the regularity is ours.
 *
 * The page is 612 x 936pt: US Letter WIDTH on a 13-inch sheet, i.e. the
 * Philippine "long bond" (8.5" x 13", Folio/F4) every MDC form is printed on.
 * dompdf defaults to A4, so setPaper([0, 0, 612, 936]) is load-bearing.
 *
 * WHY THE ANSWERS ARE WRAPPED HERE RATHER THAN BY dompdf: the answer area is a
 * stack of individually positioned RULES, not a text box. Pre-wrapping against
 * the real font metrics lets every line be placed on the rule it belongs to,
 * and as a side effect sidesteps dompdf's line-height quirk completely (a line
 * box is (line_height / font_size) * fontHeight, not line_height) — no line
 * here depends on it, because no block here holds more than one line.
 */
final class ExitInterviewFormLayout
{
    // ─────────────────────────────────────────────────────────────────────
    // Page box — measured from the reference, unchanged.
    // ─────────────────────────────────────────────────────────────────────

    public const PAGE_WIDTH = 612.0;

    public const PAGE_HEIGHT = 936.0;

    // ─────────────────────────────────────────────────────────────────────
    // The horizontal grid. ONE set of columns for BOTH pages — the reference
    // shifts its whole body 18pt between page 1 and page 2, which is the most
    // visible of its irregularities and has no reason behind it.
    //
    // Section headings sit flush with the answer rules, so the document has a
    // single strong left edge; question numbers hang 18pt in and their text a
    // further 18pt, the ordinary hanging indent the reference itself uses
    // within a page.
    // ─────────────────────────────────────────────────────────────────────

    public const MARGIN_LEFT = 72.0;        // headings, Section A labels, answer rules

    public const RULE_RIGHT = 539.75;       // the reference's own right edge

    public const NUMBER_X = 90.0;           // "12."

    public const TEXT_X = 108.0;            // question text and every wrapped line

    /** Section A's two-column split: left cells stop here, right labels start after. */
    public const COL_STOP = 330.0;

    public const COL2_LABEL_X = 342.0;

    /** A fill-in blank starts this far after the label it belongs to. */
    public const BLANK_GAP = 5.0;

    /** An inset label ("Please explain:") and the answer that follows it. */
    public const LABEL_GAP = 6.0;

    /** Clear space between a question's text and the ☐ Yes ☐ No column.
     *  Sized so that EVERY choice question's text fits beside its pair on one
     *  line — question 11 is the long one and clears it by 3.6pt. Widen this
     *  and question 11 wraps a single word onto a second line, which is the
     *  widow the layout test guards against. */
    public const CHOICE_GUTTER = 8.0;

    /** Inside the ☐ Yes ☐ No pair: box to its own label, and between the two
     *  options. */
    private const CHOICE_LABEL_GAP = 3.5;

    private const CHOICE_OPTION_GAP = 10.0;

    // ─────────────────────────────────────────────────────────────────────
    // The vertical rhythm. LINE is the form's own unit — the reference's rule
    // pitch — and everything else is stated against it.
    // ─────────────────────────────────────────────────────────────────────

    /** One line of type, and one ruled line. The reference's own 13.2pt. */
    public const LINE = 13.2;

    /** Masthead block, kept exactly as measured — it is the form's identity. */
    public const MASTHEAD = [
        ['text' => 'Mater Dei College', 'baseline' => 53.33, 'font' => 'serif', 'size' => 15.95, 'color' => '#205E99'],
        ['text' => 'Tubigon, Bohol', 'baseline' => 66.89, 'font' => 'body', 'size' => 9.5],
        ['text' => 'College of Accountancy, Business and Management (CABM)', 'baseline' => 95.45, 'font' => 'serifBold', 'size' => 14.05],
        ['text' => 'INTERNSHIP PROGRAM STUDENT EXIT', 'baseline' => 120.77, 'font' => 'bold', 'size' => 9.4],
        ['text' => 'INTERVIEW FORM', 'baseline' => 133.25, 'font' => 'bold', 'size' => 9.4],
    ];

    private const MASTHEAD_BOTTOM = 133.25;

    /** Masthead → the standing preamble. */
    private const GAP_MASTHEAD_TO_INTRO = 20.0;

    /** Any block of copy → the section heading that follows it. */
    private const GAP_TO_HEADING = 18.0;

    /** A heading → the first row or question under it. */
    private const GAP_HEADING_TO_ROW = 12.4;

    /** A question's last line of text → its first answer rule. */
    private const GAP_QUESTION_TO_RULES = 15.2;

    /** A question's last answer rule → the next question's first line. */
    private const GAP_RULES_TO_QUESTION = 11.2;

    /** A question's last answer rule → the next SECTION heading. More air
     *  than GAP_RULES_TO_QUESTION, so a section reads as a break rather than
     *  as one more question — the reference has this backwards (10.5 against
     *  11.4), which is why its sections do not announce themselves. */
    private const GAP_RULES_TO_HEADING = 16.0;

    /** Every question gets the same answer box. */
    public const ANSWER_LINES = 5;

    /** Answers sit ON their rule, this far above it — enough that a descender
     *  still clears the ink. */
    public const BASELINE_LIFT = 2.4;

    /** Page 2's first element. */
    private const PAGE_2_TOP = 58.97;

    /** Last answer rule of the questions → the "Student Signature:" row. */
    private const GAP_TO_SIGNATURE = 22.0;

    /** Between the two signatory rows, which need room for a real signature
     *  to be written by hand between the label and the rule under it. */
    private const GAP_BETWEEN_SIGNATORIES = 26.0;

    /** How far the page number's baseline sits from the page foot; no rule may
     *  reach it, or an answer would print across the folio. */
    public const FOLIO_BASELINE = 906.53;

    public const FOLIO_X = 533.76;

    // ─────────────────────────────────────────────────────────────────────
    // Type. The reference sets its body in Tahoma at 10.45pt, horizontally
    // CONDENSED to roughly 91% by the producer (every glyph carries its own Tm
    // with an `a` scale of 0.043-0.05 against a fixed `d` of 0.05). dompdf
    // cannot condense a face and Tahoma is proprietary, so the substitute is
    // Helvetica — base-14, so no font file ships. Measured at 10.45pt, the
    // preamble sets 553.71pt in real Tahoma against 550.59pt in Helvetica
    // (0.6% out), 628.24pt in DejaVu Sans (13% too wide) and 509.49pt in
    // Carlito (8% too narrow). Dropping to 9.5pt is what makes UNCONDENSED
    // Helvetica occupy the width the condensed original does.
    //
    // Times IS metric-compatible with Times New Roman, so the masthead keeps
    // the reference's own sizes.
    // ─────────────────────────────────────────────────────────────────────

    public const BODY_SIZE = 9.5;

    public const HEADING_SIZE = 9.4;

    /** dompdf places a block by its TOP; these convert a measured BASELINE.
     *  PROBED against dompdf itself, not derived — its line box is
     *  (line_height / font_size) * fontHeight, not line_height, and the
     *  arithmetic that follows from that does not reproduce the observed
     *  values. Linear in size to within 0.05pt across 9.4pt-19pt. */
    public const BASELINE_RATIO = [
        'body' => 0.81400,
        'bold' => 0.81400,
        'serif' => 0.79200,
        'serifBold' => 0.78922,
        'zapf' => 0.84740,
    ];

    /** The drawn ☐. The reference uses Segoe UI Symbol, which is proprietary
     *  and not one of dompdf's base-14 faces, so the character would render as
     *  a blank or a tofu square. A stroked box prints identically. */
    public const BOX_SIZE = 7.0;

    /**
     * A ticked box carries a CHECK MARK, and it comes from ZapfDingbats —
     * which is base-14, so like Helvetica and Times it needs no font file and
     * embeds nothing. Its `a19` glyph (character '3' in the Dingbats
     * encoding) is the check; Helvetica has no check at all, which is why an
     * X stood in for one before.
     *
     * The glyph's own metrics, per 1000 units, straight from
     * vendor/dompdf/dompdf/lib/fonts/ZapfDingbats.afm — they are what centre
     * the tick in the box instead of leaving it to eyeballed offsets.
     */
    public const CHECK_GLYPH = '3';

    public const MARK_SIZE = 7.0;

    private const CHECK_WIDTH_EM = 0.755;

    private const CHECK_TOP_EM = 0.705;

    private const CHECK_BOTTOM_EM = -0.013;

    // ─────────────────────────────────────────────────────────────────────
    // Content. Verbatim from the reference — the wording, the numbering and
    // the section order are the college's, not ours.
    // ─────────────────────────────────────────────────────────────────────

    public const PREAMBLE = [
        'This exit interview gathers feedback from students regarding their OJT/Internship experience. The information',
        'collected will help evaluate the effectiveness of the Internship program and identify areas for improvement.',
    ];

    /**
     * Section A, as a two-column grid. The reference pairs "Date of Interview"
     * with the coordinator, which leaves the coordinator's blank far too short
     * for a full name with post-nominals — the longest value on the form. Here
     * the coordinator takes a full-width row of its own and Date of Interview
     * pairs with Total Hours, so EVERY right-hand cell starts at the same x.
     */
    public const SECTION_A_ROWS = [
        [['label' => 'Student Name:', 'key' => 'student_name'], ['label' => 'Program:', 'key' => 'program']],
        [['label' => 'Company/Training Establishment:', 'key' => 'company']],
        [['label' => 'Department/Position Assigned:', 'key' => 'department_position'], ['label' => 'Training Period:', 'key' => 'training_period']],
        [['label' => 'Total Hours Completed:', 'key' => 'total_hours'], ['label' => 'Date of Interview:', 'key' => 'date_of_interview']],
        [['label' => 'OJT/INTERNSHIP Coordinator:', 'key' => 'coordinator_name']],
    ];

    /**
     * Sections B-G. `page` is stated rather than computed: the break has to
     * fall after question 7 — page 1 cannot hold an eighth answer box, and
     * moving question 7 to page 2 pushes the coordinator's block off the foot
     * of the sheet. Everything else about the flow is derived.
     */
    public const SECTIONS = [
        [
            'page' => 1,
            'heading' => 'B. Internship Placement and Responsibilities',
            'questions' => [
                ['n' => 1, 'key' => 'q1', 'text' => 'What were your primary duties and responsibilities during your internship?'],
                ['n' => 2, 'key' => 'q2', 'text' => 'Were your assigned tasks relevant to your academic program?', 'choice' => 'q2_choice', 'label' => 'Please explain:'],
            ],
        ],
        [
            'page' => 1,
            'heading' => 'C. Skills and Competencies Developed',
            'questions' => [
                ['n' => 3, 'key' => 'q3', 'text' => 'What technical skills did you learn or improve during your internship?'],
                ['n' => 4, 'key' => 'q4', 'text' => 'What soft skills did you develop during your internship? (e.g., communication, teamwork, time management, professionalism)'],
                ['n' => 5, 'key' => 'q5', 'text' => 'Which skill do you think improved the most during your training?'],
            ],
        ],
        [
            'page' => 1,
            'heading' => 'D. Internship Experience',
            'questions' => [
                ['n' => 6, 'key' => 'q6', 'text' => 'How would you describe your overall internship experience?'],
                ['n' => 7, 'key' => 'q7', 'text' => 'Were you given adequate supervision and guidance by your company supervisor?', 'choice' => 'q7_choice', 'label' => 'Please explain:'],
            ],
        ],
        [
            'page' => 2,
            'heading' => 'E. Challenges Encountered',
            'questions' => [
                ['n' => 8, 'key' => 'q8', 'text' => 'What challenges did you encounter during your internship? How did you address these challenges?'],
            ],
        ],
        [
            'page' => 2,
            'heading' => 'F. Learning and Career Insights',
            'questions' => [
                ['n' => 9, 'key' => 'q9', 'text' => 'What important lessons did you learn from your internship?'],
                ['n' => 10, 'key' => 'q10', 'text' => 'Did your internship influence your career plans?', 'choice' => 'q10_choice', 'label' => 'If yes, please explain:'],
                ['n' => 11, 'key' => 'q11', 'text' => 'Do you feel prepared to enter the workforce after completing your OJT/INTERNSHIP?', 'choice' => 'q11_choice'],
            ],
        ],
        [
            'page' => 2,
            'heading' => 'G. Feedback and Recommendations',
            'questions' => [
                ['n' => 12, 'key' => 'q12', 'text' => 'What aspects of the OJT/INTERNSHIP program were most beneficial to you?'],
                ['n' => 13, 'key' => 'q13', 'text' => 'What improvements would you suggest for the OJT/INTERNSHIP program?'],
                ['n' => 14, 'key' => 'q14', 'text' => 'What advice would you give to future OJT/INTERNSHIP students?'],
            ],
        ],
    ];

    /** The two compliance options, and the coordinator's own free-text fields. */
    public const COMPLIANCE_OPTIONS = [
        'complete' => 'Yes, all requirements completed',
        'pending' => 'With pending requirements',
    ];

    public const REMARKS_LINES = 3;

    // ─────────────────────────────────────────────────────────────────────
    // Answer length. See fits() — the binding check is a width measurement.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A generous per-line character budget, used ONLY to bound the textarea in
     * the SPA and to reject an absurd payload cheaply.
     *
     * Ordinary prose sets around 0.45em per character in Helvetica (measured:
     * 4.24-4.30pt at 9.5pt, so ~109 characters to a 467.75pt rule), while an
     * ALL-CAPS answer sets 0.60em (~82). No single character count can be both
     * generous to the first and safe for the second, which is exactly why the
     * binding check is a width measurement rather than a length one.
     */
    public const CHARS_PER_LINE = 100;

    public const ANSWER_CHAR_LIMIT = self::ANSWER_LINES * self::CHARS_PER_LINE;

    /** Nothing on this form is ever a legitimate essay; this is the cheap gate
     *  that stops a megabyte reaching the measurer. */
    public const HARD_CHAR_CAP = 800;

    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    /** @var array<int, int>|null Helvetica advance widths, per 1000 units. */
    private static ?array $widths = null;

    // ─────────────────────────────────────────────────────────────────────
    // The computed document.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Everything that does not depend on a particular student's answers: every
     * text run, rule, blank and checkbox, already positioned.
     *
     * @return array{texts: array<int, array<string, mixed>>, rules: array<int, array<string, mixed>>, boxes: array<int, array<string, mixed>>, fields: array<string, array<int, array{page: int, x: float, y: float, w: float}>>, blanks: array<string, array{page: int, x: float, y: float, w: float, baseline: float}>, marks: array<string, array{page: int, x: float, baseline: float}>, bottom: array<int, float>}
     */
    public static function document(): array
    {
        if (self::$document !== null) {
            return self::$document;
        }

        $texts = [];
        $rules = [];
        $boxes = [];
        $fields = [];
        $blanks = [];
        $marks = [];

        // ── Masthead, centred on the PAGE (the reference centres it on x 306,
        //    not on the ruled column). ────────────────────────────────────
        foreach (self::MASTHEAD as $line) {
            $texts[] = [
                'page' => 1,
                'x' => 0.0,
                'w' => self::PAGE_WIDTH,
                'align' => 'center',
                'baseline' => $line['baseline'],
                'font' => $line['font'],
                'size' => $line['size'],
                'text' => $line['text'],
                'color' => $line['color'] ?? null,
            ];
        }

        // ── Standing preamble ───────────────────────────────────────────
        $y = self::MASTHEAD_BOTTOM + self::GAP_MASTHEAD_TO_INTRO;

        foreach (self::PREAMBLE as $index => $line) {
            $texts[] = self::run(1, self::MARGIN_LEFT, $y + ($index * self::LINE), $line);
        }

        $y += (count(self::PREAMBLE) - 1) * self::LINE;

        // ── A. Student Information ──────────────────────────────────────
        $y += self::GAP_TO_HEADING;
        $texts[] = self::heading(1, $y, 'A. Student Information');

        $y += self::GAP_HEADING_TO_ROW;

        foreach (self::SECTION_A_ROWS as $rowIndex => $cells) {
            $rowY = $y + ($rowIndex * self::LINE);

            foreach ($cells as $cellIndex => $cell) {
                $labelX = $cellIndex === 0 ? self::MARGIN_LEFT : self::COL2_LABEL_X;
                $stop = $cellIndex === 0 && count($cells) > 1 ? self::COL_STOP : self::RULE_RIGHT;

                $texts[] = self::run(1, $labelX, $rowY, $cell['label']);

                $blankX = $labelX + self::textWidth($cell['label']) + self::BLANK_GAP;

                // The underline sits just below the baseline, on the same
                // offset an answer uses against its own rule.
                $rules[] = ['page' => 1, 'x' => $blankX, 'y' => $rowY + self::BASELINE_LIFT, 'w' => $stop - $blankX];

                $blanks[$cell['key']] = [
                    'page' => 1,
                    'x' => $blankX + 3.0,
                    'y' => $rowY + self::BASELINE_LIFT,
                    'w' => $stop - $blankX - 3.0,
                    'baseline' => $rowY,
                ];
            }
        }

        $y += (count(self::SECTION_A_ROWS) - 1) * self::LINE;

        // ── B - G: the questions ────────────────────────────────────────
        $choiceX = self::RULE_RIGHT - self::choicePairWidth();
        $page = 1;

        foreach (self::SECTIONS as $section) {
            if ($section['page'] !== $page) {
                $page = $section['page'];
                $y = self::PAGE_2_TOP - self::GAP_RULES_TO_HEADING;
            }

            $y += self::GAP_RULES_TO_HEADING;
            $texts[] = self::heading($page, $y, $section['heading']);
            $y += self::GAP_HEADING_TO_ROW;

            foreach ($section['questions'] as $qIndex => $question) {
                if ($qIndex > 0) {
                    $y += self::GAP_RULES_TO_QUESTION;
                }

                $hasChoice = isset($question['choice']);

                // A question carrying a ☐ Yes ☐ No pair wraps short of the
                // choice column, so the pair can sit in the SAME place on
                // every one of them instead of trailing whatever length the
                // question happened to be.
                $textWidth = ($hasChoice ? $choiceX - self::CHOICE_GUTTER : self::RULE_RIGHT) - self::TEXT_X;

                $lines = self::wrap($question['text'], array_fill(0, 4, $textWidth));

                $texts[] = self::run($page, self::NUMBER_X, $y, $question['n'].'.');

                foreach ($lines as $index => $line) {
                    $texts[] = self::run($page, self::TEXT_X, $y + ($index * self::LINE), $line);
                }

                $y += (count($lines) - 1) * self::LINE;

                if ($hasChoice) {
                    foreach (self::choicePair($choiceX) as $option) {
                        $boxes[] = ['page' => $page, 'x' => $option['box'], 'y' => $y - self::BOX_SIZE - 0.2];
                        $texts[] = self::run($page, $option['label_x'], $y, $option['label']);

                        $marks[$question['choice'].':'.$option['value']] = self::check($page, $option['box'], $y - self::BOX_SIZE - 0.2);
                    }
                }

                // ── The answer box ──────────────────────────────────────
                $first = $y + self::GAP_QUESTION_TO_RULES;
                $lineSpecs = [];

                for ($i = 0; $i < self::ANSWER_LINES; $i++) {
                    $ruleY = $first + ($i * self::LINE);
                    $rules[] = ['page' => $page, 'x' => self::MARGIN_LEFT, 'y' => $ruleY, 'w' => self::RULE_RIGHT - self::MARGIN_LEFT];

                    $inset = 0.0;

                    // "Please explain:" rides the FIRST answer line rather
                    // than being orphaned onto a line of its own at a
                    // different indent — the same treatment the coordinator's
                    // "If pending, specify:" already gets.
                    if ($i === 0 && isset($question['label'])) {
                        $texts[] = self::run($page, self::MARGIN_LEFT, $ruleY - self::BASELINE_LIFT, $question['label']);
                        $inset = self::textWidth($question['label']) + self::LABEL_GAP;
                    }

                    $lineSpecs[] = [
                        'page' => $page,
                        'x' => self::MARGIN_LEFT + $inset,
                        'y' => $ruleY,
                        'w' => self::RULE_RIGHT - self::MARGIN_LEFT - $inset,
                    ];
                }

                $fields[$question['key']] = $lineSpecs;
                $y = $first + ((self::ANSWER_LINES - 1) * self::LINE);
            }
        }

        $questionsBottom = $y;

        // ── Student signature ───────────────────────────────────────────
        $y += self::GAP_TO_SIGNATURE;

        foreach ([['Student Signature:', self::MARGIN_LEFT, self::COL_STOP], ['Date:', self::COL2_LABEL_X, self::RULE_RIGHT]] as [$label, $labelX, $stop]) {
            $texts[] = self::run(2, $labelX, $y, $label);
            $blankX = $labelX + self::textWidth($label) + self::BLANK_GAP;
            $rules[] = ['page' => 2, 'x' => $blankX, 'y' => $y + self::BASELINE_LIFT, 'w' => $stop - $blankX];
        }

        // ── Section for the OJT/Internship Coordinator ──────────────────
        $y += self::GAP_TO_HEADING;
        $texts[] = self::heading(2, $y, 'SECTION FOR OJT/INTERNSHIP COORDINATOR');

        $y += self::GAP_HEADING_TO_ROW;
        $texts[] = self::heading(2, $y, 'Compliance Verification:');
        $texts[] = self::run(
            2,
            self::MARGIN_LEFT + self::textWidth('Compliance Verification:', self::HEADING_SIZE) + self::BLANK_GAP,
            $y,
            'Did the student submit all required OJT/INTERNSHIP documents and reports?'
        );

        foreach (self::COMPLIANCE_OPTIONS as $value => $label) {
            $y += self::LINE;
            $boxes[] = ['page' => 2, 'x' => self::TEXT_X, 'y' => $y - self::BOX_SIZE - 0.2];
            $texts[] = self::run(2, self::TEXT_X + self::BOX_SIZE + 5.0, $y, $label);
            $marks['compliance:'.$value] = self::check(2, self::TEXT_X, $y - self::BOX_SIZE - 0.2);
        }

        // "If pending, specify:" — the same inset-label pattern as the
        // questions' "Please explain:", so the label sits ON its first rule
        // rather than orphaned above it.
        $y += self::LINE + self::BASELINE_LIFT;
        $texts[] = self::run(2, self::MARGIN_LEFT, $y - self::BASELINE_LIFT, 'If pending, specify:');
        $inset = self::textWidth('If pending, specify:') + self::LABEL_GAP;

        $pending = [];

        for ($i = 0; $i < 2; $i++) {
            $ruleY = $y + ($i * self::LINE);
            $rules[] = ['page' => 2, 'x' => self::MARGIN_LEFT, 'y' => $ruleY, 'w' => self::RULE_RIGHT - self::MARGIN_LEFT];
            $lineInset = $i === 0 ? $inset : 0.0;
            $pending[] = [
                'page' => 2,
                'x' => self::MARGIN_LEFT + $lineInset,
                'y' => $ruleY,
                'w' => self::RULE_RIGHT - self::MARGIN_LEFT - $lineInset,
            ];
        }

        $fields['pending_detail'] = $pending;
        $y += self::LINE;

        // Remarks: a label with clear ruled space under it, on the same
        // rhythm as every other answer box.
        $y += self::GAP_RULES_TO_HEADING;
        $texts[] = self::heading(2, $y, 'Remarks:');

        $y += self::GAP_QUESTION_TO_RULES;
        $remarks = [];

        for ($i = 0; $i < self::REMARKS_LINES; $i++) {
            $ruleY = $y + ($i * self::LINE);
            $rules[] = ['page' => 2, 'x' => self::MARGIN_LEFT, 'y' => $ruleY, 'w' => self::RULE_RIGHT - self::MARGIN_LEFT];
            $remarks[] = ['page' => 2, 'x' => self::MARGIN_LEFT, 'y' => $ruleY, 'w' => self::RULE_RIGHT - self::MARGIN_LEFT];
        }

        $fields['remarks'] = $remarks;
        $y += (self::REMARKS_LINES - 1) * self::LINE;

        // ── Signatories ─────────────────────────────────────────────────
        $y += self::GAP_TO_HEADING;
        [$blanks['coordinator_reviewed_on']] = self::signatory($texts, $rules, $y, 'OJT/INTERNSHIP Coordinator Signature:', 'coordinator_signature_name');

        $y += self::GAP_BETWEEN_SIGNATORIES;
        [$blanks['dean_reviewed_on'], $deanNameX] = self::signatory($texts, $rules, $y, 'Reviewed by:', 'dean_name');

        // "Dean" captions the name above it, so it is set at the name's own
        // left edge rather than at an offset nobody can re-derive.
        $texts[] = self::run(2, $deanNameX, $y + self::LINE, 'Dean');

        $signatoryBottom = $y + self::LINE;

        // ── Folio ───────────────────────────────────────────────────────
        foreach ([1, 2] as $folio) {
            $texts[] = [
                'page' => $folio,
                'x' => self::FOLIO_X,
                'w' => null,
                'align' => 'left',
                'baseline' => self::FOLIO_BASELINE,
                'font' => 'serif',
                'size' => 12.0,
                'text' => (string) $folio,
                'color' => null,
            ];
        }

        return self::$document = [
            'texts' => $texts,
            'rules' => $rules,
            'boxes' => $boxes,
            'fields' => $fields,
            'blanks' => $blanks,
            'marks' => $marks,
            // The lowest ink on each page, so a test can prove nothing runs
            // into the folio.
            'bottom' => [
                1 => self::pageBottom($rules, 1),
                2 => max(self::pageBottom($rules, 2), $signatoryBottom),
            ],
            'questions_bottom' => $questionsBottom,
        ];
    }

    /**
     * Every answer rule, keyed by field, in the shape the rest of the app
     * expects: [page, y] pairs.
     *
     * @return array<string, array<int, array{0: int, 1: float}>>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::document()['fields'] as $key => $lines) {
            $rules[$key] = array_map(fn (array $line) => [$line['page'], round($line['y'], 2)], $lines);
        }

        return $rules;
    }

    /**
     * Lay a set of answers onto the form.
     *
     * @param  array<string, string|null>  $answers
     * @return array<int, array{page: int, left: float, width: float, baseline: float, text: string}>
     */
    public static function place(array $answers): array
    {
        $placed = [];

        foreach (self::document()['fields'] as $key => $lines) {
            $text = trim((string) ($answers[$key] ?? ''));

            if ($text === '') {
                continue;
            }

            $widths = array_column($lines, 'w');

            foreach (self::wrap($text, $widths) as $index => $line) {
                $placed[] = [
                    'page' => $lines[$index]['page'],
                    'left' => $lines[$index]['x'],
                    'width' => $lines[$index]['w'],
                    'baseline' => $lines[$index]['y'] - self::BASELINE_LIFT,
                    'text' => $line,
                ];
            }
        }

        return $placed;
    }

    /**
     * The soft character cap for one field, from how many rules it has.
     */
    public static function charLimitFor(string $key): int
    {
        return count(self::rules()[$key] ?? []) * self::CHARS_PER_LINE ?: self::ANSWER_CHAR_LIMIT;
    }

    /**
     * @return array<string, int>
     */
    public static function charLimits(): array
    {
        $limits = [];

        foreach (array_keys(self::rules()) as $key) {
            $limits[$key] = self::charLimitFor($key);
        }

        return $limits;
    }

    /**
     * Does this answer actually fit the rules printed for it?
     *
     * THIS is the real guarantee, and it is a measurement rather than a
     * character count for the reason spelled out on CHARS_PER_LINE: the same
     * 450 characters fit comfortably in ordinary prose and overrun by a line
     * and a half in capitals. wrap() silently drops whatever will not fit —
     * which is correct at render time, since a PDF cannot refuse — so the
     * refusal has to happen at validation time instead.
     */
    public static function fits(string $key, ?string $text): bool
    {
        $text = trim((string) $text);
        $lines = self::document()['fields'][$key] ?? null;

        if ($text === '' || $lines === null) {
            return true;
        }

        // Measure against a line budget one wider than the form has, so an
        // answer that needs the extra line is visibly over rather than
        // truncated into looking as though it fitted.
        $widths = array_column($lines, 'w');
        $widths[] = end($widths);

        return count(self::wrap($text, $widths)) <= count($lines);
    }

    /**
     * Greedy word wrap against the real Helvetica advance widths dompdf will
     * use, into at most count($lineWidths) lines of the given widths.
     *
     * A word wider than its line is broken mid-word rather than allowed to
     * overhang the rule — a pasted URL must not run off the page. Anything
     * past the last available line is dropped, which the character limit
     * exists to stop ever happening in practice.
     *
     * @param  array<int, float>  $lineWidths
     * @return array<int, string>
     */
    public static function wrap(string $text, array $lineWidths): array
    {
        // One rule is one line: a student's own newlines would otherwise cost
        // a whole rule apiece on a form that has five of them.
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $limit = $lineWidths[count($lines)] ?? null;

            if ($limit === null) {
                break;
            }

            $candidate = $current === '' ? $word : $current.' '.$word;

            if (self::textWidth($candidate) <= $limit) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
                $current = '';
                $limit = $lineWidths[count($lines)] ?? null;

                if ($limit === null) {
                    break;
                }
            }

            while ($word !== '' && self::textWidth($word) > $limit) {
                $take = 1;

                while ($take < mb_strlen($word) && self::textWidth(mb_substr($word, 0, $take + 1)) <= $limit) {
                    $take++;
                }

                $lines[] = mb_substr($word, 0, $take);
                $word = mb_substr($word, $take);

                $limit = $lineWidths[count($lines)] ?? null;

                if ($limit === null) {
                    return $lines;
                }
            }

            $current = $word;
        }

        if ($current !== '' && count($lines) < count($lineWidths)) {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * The width of a string at BODY_SIZE, from dompdf's own Helvetica metrics.
     *
     * Reading the very AFM the renderer reads is what keeps the wrap and the
     * render in agreement. If the file is ever unreadable, fall back to a mean
     * advance rather than throwing — a slightly ragged form still downloads.
     */
    public static function textWidth(string $text, float $size = self::BODY_SIZE): float
    {
        $widths = self::widths();

        $units = 0;

        foreach (self::winAnsi($text) as $code) {
            $units += $widths[$code] ?? 556;
        }

        return $units / 1000 * $size;
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * A check mark centred in the box at ($boxX, $boxTop), from the glyph's
     * own metrics rather than from eyeballed offsets.
     *
     * @return array{page: int, x: float, baseline: float}
     */
    private static function check(int $page, float $boxX, float $boxTop): array
    {
        $glyphCentreBelowBaseline = (self::CHECK_TOP_EM + self::CHECK_BOTTOM_EM) / 2 * self::MARK_SIZE;

        return [
            'page' => $page,
            'x' => $boxX + ((self::BOX_SIZE - (self::CHECK_WIDTH_EM * self::MARK_SIZE)) / 2),
            'baseline' => $boxTop + (self::BOX_SIZE / 2) + $glyphCentreBelowBaseline,
        ];
    }

    /**
     * @return array<int, array{value: string, box: float, label_x: float, label: string}>
     */
    private static function choicePair(float $choiceX): array
    {
        $yesLabelX = $choiceX + self::BOX_SIZE + self::CHOICE_LABEL_GAP;
        $noBoxX = $yesLabelX + self::textWidth('Yes') + self::CHOICE_OPTION_GAP;

        return [
            ['value' => 'yes', 'box' => $choiceX, 'label_x' => $yesLabelX, 'label' => 'Yes'],
            ['value' => 'no', 'box' => $noBoxX, 'label_x' => $noBoxX + self::BOX_SIZE + self::CHOICE_LABEL_GAP, 'label' => 'No'],
        ];
    }

    private static function choicePairWidth(): float
    {
        return (self::BOX_SIZE + self::CHOICE_LABEL_GAP + self::textWidth('Yes'))
            + self::CHOICE_OPTION_GAP
            + (self::BOX_SIZE + self::CHOICE_LABEL_GAP + self::textWidth('No'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $texts
     * @param  array<int, array<string, mixed>>  $rules
     * @return array{0: array{page: int, x: float, y: float, w: float, baseline: float}, 1: float}
     */
    private static function signatory(array &$texts, array &$rules, float $y, string $label, string $nameKey): array
    {
        $texts[] = self::heading(2, $y, $label);

        $nameX = self::MARGIN_LEFT + self::textWidth($label, self::HEADING_SIZE) + self::BLANK_GAP;

        $texts[] = ['page' => 2, 'x' => $nameX, 'w' => null, 'align' => 'left', 'baseline' => $y, 'font' => 'bold', 'size' => self::HEADING_SIZE, 'text' => '{'.$nameKey.'}', 'color' => null];

        $texts[] = self::run(2, self::COL2_LABEL_X + 60.0, $y, 'Date:');

        $blankX = self::COL2_LABEL_X + 60.0 + self::textWidth('Date:') + self::BLANK_GAP;
        $rules[] = ['page' => 2, 'x' => $blankX, 'y' => $y + self::BASELINE_LIFT, 'w' => self::RULE_RIGHT - $blankX];

        return [
            ['page' => 2, 'x' => $blankX + 3.0, 'y' => $y + self::BASELINE_LIFT, 'w' => self::RULE_RIGHT - $blankX - 3.0, 'baseline' => $y],
            $nameX,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function run(int $page, float $x, float $baseline, string $text): array
    {
        return ['page' => $page, 'x' => $x, 'w' => null, 'align' => 'left', 'baseline' => $baseline, 'font' => 'body', 'size' => self::BODY_SIZE, 'text' => $text, 'color' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function heading(int $page, float $baseline, string $text): array
    {
        return ['page' => $page, 'x' => self::MARGIN_LEFT, 'w' => null, 'align' => 'left', 'baseline' => $baseline, 'font' => 'bold', 'size' => self::HEADING_SIZE, 'text' => $text, 'color' => null];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    private static function pageBottom(array $rules, int $page): float
    {
        $ys = array_map(
            fn (array $rule) => $rule['y'],
            array_filter($rules, fn (array $rule) => $rule['page'] === $page)
        );

        return $ys === [] ? 0.0 : max($ys);
    }

    /**
     * @return array<int, int>
     */
    private static function widths(): array
    {
        if (self::$widths !== null) {
            return self::$widths;
        }

        $path = base_path('vendor/dompdf/dompdf/lib/fonts/Helvetica.afm.json');

        $decoded = is_file($path)
            ? json_decode((string) file_get_contents($path), true)
            : null;

        return self::$widths = is_array($decoded['C'] ?? null)
            ? array_map('intval', $decoded['C'])
            : [];
    }

    /**
     * Helvetica is a single-byte base-14 face, so measurement works on the
     * WinAnsi code points. An unmappable character is measured as an 'x'
     * rather than dropped, so the wrap can never run short.
     *
     * @return array<int, int>
     */
    private static function winAnsi(string $text): array
    {
        $codes = [];

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $byte = @iconv('UTF-8', 'Windows-1252//IGNORE', $char);
            $codes[] = $byte === false || $byte === '' ? 120 : ord($byte);
        }

        return $codes;
    }
}
