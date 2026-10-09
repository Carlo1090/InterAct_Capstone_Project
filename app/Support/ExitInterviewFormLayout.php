<?php

namespace App\Support;

use App\Support\ExitInterview\ExitInterviewForm;
use App\Support\ExitInterview\ExitInterviewForms;

/**
 * The layout of an exit interview form — a COMPUTED document, not a list of
 * hand-placed coordinates.
 *
 * ONE LAYOUT, MANY FORMS (2026-09-15). The geometry below was measured off the
 * CABM "Internship Program Student Exit Interview Form" and every department's
 * form is laid onto it: the same masthead block, Section A, rule pitch, answer
 * box, coordinator block and signatories. What varies per form is the question
 * set (App\Support\ExitInterview\ExitInterviewForm), so an instance is built
 * per form via Layout::for() and the questions are PAGINATED AUTOMATICALLY —
 * a page breaks before any question whose answer box would run into the
 * folio, a section heading never sits alone at the foot of a page, and the
 * coordinator block moves to a fresh page when the last page cannot hold it.
 * For the 14-question CABM form that reproduces the original hand-stated
 * break after question 7 exactly; the 31-question CAST form runs to more
 * pages, and each is computed rather than stated.
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
    // The horizontal grid. ONE set of columns for EVERY page — the reference
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

    /** Between the options of a rating row (☐ Excellent ☐ Very Good …), which
     *  sits on its own line under the question rather than beside it. */
    private const SCALE_OPTION_GAP = 14.0;

    // ─────────────────────────────────────────────────────────────────────
    // The vertical rhythm. LINE is the form's own unit — the reference's rule
    // pitch — and everything else is stated against it.
    // ─────────────────────────────────────────────────────────────────────

    /** One line of type, and one ruled line. The reference's own 13.2pt. */
    public const LINE = 13.2;

    /**
     * Masthead block, kept exactly as measured — it is the form's identity.
     * The college line and the two title lines are the FORM's own
     * (ExitInterviewForm::$collegeLine / $titleLines); only the baselines,
     * faces and sizes are fixed here. A one-line title leaves the second
     * baseline empty rather than shifting anything up.
     */
    public const MASTHEAD = [
        ['text' => 'Mater Dei College', 'baseline' => 53.33, 'font' => 'serif', 'size' => 15.95, 'color' => '#205E99'],
        ['text' => 'Tubigon, Bohol', 'baseline' => 66.89, 'font' => 'body', 'size' => 9.5],
        ['slot' => 'college', 'baseline' => 95.45, 'font' => 'serifBold', 'size' => 14.05],
        ['slot' => 'title:0', 'baseline' => 120.77, 'font' => 'bold', 'size' => 9.4],
        ['slot' => 'title:1', 'baseline' => 133.25, 'font' => 'bold', 'size' => 9.4],
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

    /** The first baseline on every page after the first. */
    private const PAGE_2_TOP = 58.97;

    /**
     * No rule or run may sit below this; a question whose answer box would is
     * moved to the next page instead. It is FOLIO_BASELINE less the 20pt
     * clearance the layout test demands and a little more, so the guarantee
     * holds by construction rather than by the luck of a question count. It
     * leaves the CABM form's own measured pages unchanged: its page 2 ends at
     * 871.37, and its page 1 could not have held question 8 either way.
     */
    private const CONTENT_BOTTOM = 882.5;

    /** Last answer rule of the questions → the "Student Signature:" row. */
    private const GAP_TO_SIGNATURE = 22.0;

    /**
     * Clear space above each signatory's printed name for the signature
     * itself — from the last Remarks rule down to the coordinator's name, and
     * from the coordinator's line down to the dean's. About 9mm; the
     * signature then runs onto the printed name in the ordinary way
     * ("signature over printed name").
     *
     * It was 11pt (about 4mm) until 2026-10-08, which left the coordinator
     * nowhere to sign under Remarks. It is as large as the CABM form's second
     * page allows: that page carries questions 8-14 and this whole block, and
     * still has to end clear of the folio.
     */
    public const SIGNING_ROOM = 26.5;

    /** Helvetica-Bold's cap height (718/1000 em) at the size names print in —
     *  what the signing room is measured down to. */
    private const NAME_CAP_HEIGHT = 0.718 * self::HEADING_SIZE;

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
    // Content that is the TEMPLATE's rather than any one form's: Section A,
    // the coordinator block. The questions come from the form.
    // ─────────────────────────────────────────────────────────────────────

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

    /** The two compliance options, and the coordinator's own free-text fields. */
    public const COMPLIANCE_OPTIONS = [
        'complete' => 'Yes, all requirements completed',
        'pending' => 'With pending requirements',
    ];

    public const REMARKS_LINES = 3;

    /**
     * The two signatories at the foot of the form, in printed order: the
     * label (one line or two — a two-line label ends on the name's line), the
     * header key their printed name is read from (the coordinator TYPES both
     * names on their review; see BuildsExitInterviewPdf), the header key of
     * the date printed beside them, and the role captioned under the name.
     *
     * Each signatory is ONE line, as on the reference — label, NAME, Date —
     * with both names in one column and both dates in another. The reference
     * fits that line only because its Tahoma is condensed, and still leaves
     * the coordinator about 25pt to date in. At this type size the
     * coordinator's label (183pt bold) and a full name with post-nominals
     * (210pt) would take 400 of the 468pt column before the date, so the
     * label is set over two lines: "OJT/INTERNSHIP" above "Coordinator
     * Signature:". Its first line sits beside the signing room, so the split
     * costs no height.
     */
    public const SIGNATORIES = [
        'coordinator' => ['label' => ['OJT/INTERNSHIP', 'Coordinator Signature:'], 'name' => 'coordinator_signature_name', 'date' => 'coordinator_reviewed_on', 'caption' => null],
        'dean' => ['label' => ['Reviewed by:'], 'name' => 'dean_name', 'date' => 'dean_reviewed_on', 'caption' => 'Dean'],
    ];

    /** The widest signatory label line → the printed-name column. */
    private const SIGNATORY_NAME_GAP = 12.0;

    /** Each signatory's date blank: room for "Sep 30, 2026" printed (54pt)
     *  or a date written by hand. */
    public const SIGNATORY_DATE_BLANK = 72.0;

    /** Clear space kept between a name, a handwriting line or a caption and
     *  the "Date:" beside it. */
    private const SIGNATORY_DATE_GAP = 12.0;

    /** Printed names are set at the heading size and stepped down no further
     *  than this to fit their column — and a typed name is refused before it
     *  would need to be (signatoryNameFits()). */
    public const SIGNATORY_MIN_SIZE = 7.5;

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

    /** @var array<string, self> One instance per form key. */
    private static array $instances = [];

    /** @var array<string, array<int, int>> Helvetica advance widths, per 1000 units, per face. */
    private static array $widths = [];

    /** @var array<string, mixed>|null */
    private ?array $document = null;

    private function __construct(public readonly ExitInterviewForm $form) {}

    /**
     * The layout for one form. Cached per key, since document() is a few
     * thousand width measurements.
     */
    public static function for(ExitInterviewForm|string $form): self
    {
        $form = $form instanceof ExitInterviewForm ? $form : ExitInterviewForms::get($form);

        return self::$instances[$form->key] ??= new self($form);
    }

    // ─────────────────────────────────────────────────────────────────────
    // The computed document.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Everything that does not depend on a particular student's answers: every
     * text run, rule, blank and checkbox, already positioned and paginated.
     *
     * @return array{pages: int, texts: array<int, array<string, mixed>>, rules: array<int, array<string, mixed>>, boxes: array<int, array<string, mixed>>, fields: array<string, array<int, array{page: int, x: float, y: float, w: float}>>, blanks: array<string, array{page: int, x: float, y: float, w: float, baseline: float}>, marks: array<string, array{page: int, x: float, baseline: float}>, signatories: array<string, array<string, mixed>>, bottom: array<int, float>, questions_bottom: float}
     */
    public function document(): array
    {
        if ($this->document !== null) {
            return $this->document;
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
            $text = $line['text'] ?? $this->mastheadSlot($line['slot']);

            if ($text === null) {
                continue;
            }

            $texts[] = [
                'page' => 1,
                'x' => 0.0,
                'w' => self::PAGE_WIDTH,
                'align' => 'center',
                'baseline' => $line['baseline'],
                'font' => $line['font'],
                'size' => $line['size'],
                'text' => $text,
                'color' => $line['color'] ?? null,
            ];
        }

        // ── Standing preamble, where the form carries one ───────────────
        $y = self::MASTHEAD_BOTTOM;

        if ($this->form->preamble !== []) {
            $y += self::GAP_MASTHEAD_TO_INTRO;

            foreach ($this->form->preamble as $index => $line) {
                $texts[] = self::run(1, self::MARGIN_LEFT, $y + ($index * self::LINE), $line);
            }

            $y += (count($this->form->preamble) - 1) * self::LINE;
        }

        // ── Student information (the template's Section A) ──────────────
        $y += self::GAP_TO_HEADING;
        $texts[] = self::heading(1, $y, $this->form->studentInfoHeading);

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

        // ── The questions, paginated ────────────────────────────────────
        $choiceX = self::RULE_RIGHT - self::choicePairWidth();
        $page = 1;

        foreach ($this->form->normalisedSections() as $section) {
            $questions = $section['questions'];

            // A heading and its first question travel together: a heading
            // alone at the foot of a page announces a section that starts
            // overleaf, which is the orphan every typesetter avoids.
            $need = self::GAP_RULES_TO_HEADING + self::GAP_HEADING_TO_ROW
                + $this->questionHeight($questions[0], $choiceX);

            if ($y + $need > self::CONTENT_BOTTOM) {
                $page++;
                $y = self::PAGE_2_TOP - self::GAP_RULES_TO_HEADING;
            }

            $y += self::GAP_RULES_TO_HEADING;
            $texts[] = self::heading($page, $y, $section['heading']);
            $y += self::GAP_HEADING_TO_ROW;

            foreach ($questions as $qIndex => $question) {
                if ($qIndex > 0) {
                    if ($y + self::GAP_RULES_TO_QUESTION + $this->questionHeight($question, $choiceX) > self::CONTENT_BOTTOM) {
                        $page++;
                        $y = self::PAGE_2_TOP;
                    } else {
                        $y += self::GAP_RULES_TO_QUESTION;
                    }
                }

                $y = $this->placeQuestion($question, $page, $y, $choiceX, $texts, $rules, $boxes, $fields, $marks);
            }
        }

        $questionsBottom = $y;

        // ── The coordinator's block and the signatories ─────────────────
        // Measured first on scratch arrays, so it can be moved whole to a
        // fresh page when the last page of questions cannot hold it — a
        // signature block split across a page break is worse than a page
        // that is mostly blank.
        $scratch = [[], [], [], [], [], [], []];
        $trailerHeight = $this->placeTrailer(0, 0.0, ...$scratch);

        if ($y + $trailerHeight > self::CONTENT_BOTTOM) {
            $page++;
            $y = self::PAGE_2_TOP - self::GAP_TO_SIGNATURE;
        }

        $signatories = [];
        $signatoryBottom = $this->placeTrailer($page, $y, $texts, $rules, $boxes, $fields, $blanks, $marks, $signatories);

        // ── Folio ───────────────────────────────────────────────────────
        for ($folio = 1; $folio <= $page; $folio++) {
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

        $bottom = [];

        for ($p = 1; $p <= $page; $p++) {
            $bottom[$p] = self::pageBottom($rules, $p);
        }

        $bottom[$page] = max($bottom[$page], $signatoryBottom);

        return $this->document = [
            'pages' => $page,
            'texts' => $texts,
            'rules' => $rules,
            'boxes' => $boxes,
            'fields' => $fields,
            'blanks' => $blanks,
            'marks' => $marks,
            // Where each signatory's printed name goes; placeSignatories()
            // sets the actual names onto these.
            'signatories' => $signatories,
            // The lowest ink on each page, so a test can prove nothing runs
            // into the folio.
            'bottom' => $bottom,
            'questions_bottom' => $questionsBottom,
        ];
    }

    /**
     * Every answer rule, keyed by field, in the shape the rest of the app
     * expects: [page, y] pairs.
     *
     * @return array<string, array<int, array{0: int, 1: float}>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->document()['fields'] as $key => $lines) {
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
    public function place(array $answers): array
    {
        $placed = [];

        foreach ($this->document()['fields'] as $key => $lines) {
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
     * Set the two signatories' printed names onto the form — the
     * placeSignatories() counterpart of place(), and for the same reason: the
     * names vary per interview, so the layout positions them here rather than
     * leaving geometry to the blade.
     *
     * A name prints in bold capitals, as the reference's pre-printed names do,
     * with no rule under it — the person signs over their printed name. With
     * no name on record a line is drawn instead, for it to be written by hand.
     * A role caption ("Dean") is centred under whichever it is, and kept clear
     * of the Date column beside it.
     *
     * @param  array<string, string|null>  $names  keyed by SIGNATORIES' `name` keys
     * @return array{texts: array<int, array<string, mixed>>, rules: array<int, array<string, mixed>>}
     */
    public function placeSignatories(array $names): array
    {
        $texts = [];
        $rules = [];

        foreach ($this->document()['signatories'] as $slot) {
            $name = mb_strtoupper(trim((string) ($names[$slot['name']] ?? '')));

            if ($name === '') {
                $rules[] = ['page' => $slot['page'], 'x' => $slot['x'], 'y' => $slot['baseline'] + self::BASELINE_LIFT, 'w' => $slot['line_w']];
                $nameWidth = $slot['line_w'];
            } else {
                [$name, $size] = self::fitSignatoryName($name, $slot['w']);
                $nameWidth = self::textWidth($name, $size, true);

                $texts[] = ['page' => $slot['page'], 'x' => $slot['x'], 'w' => null, 'align' => 'left', 'baseline' => $slot['baseline'], 'font' => 'bold', 'size' => $size, 'text' => $name, 'color' => null];
            }

            if ($slot['caption'] !== null) {
                $captionWidth = self::textWidth($slot['caption']);
                $captionX = $slot['x'] + (($nameWidth - $captionWidth) / 2);
                $captionX = max($slot['x'], min($captionX, $slot['caption_stop'] - $captionWidth));

                $texts[] = self::run($slot['page'], round($captionX, 2), $slot['baseline'] + self::LINE, $slot['caption']);
            }
        }

        return ['texts' => $texts, 'rules' => $rules];
    }

    /**
     * Does a typed signatory name fit its printed column at full size? It
     * prints in bold capitals, so that is what is measured — the same reason
     * fits() measures an answer rather than counting its characters.
     */
    public static function signatoryNameFits(?string $name): bool
    {
        $name = mb_strtoupper(trim((string) $name));

        return $name === '' || self::textWidth($name, self::HEADING_SIZE, true) <= self::signatoryNameWidth();
    }

    /**
     * The printed-name column: one x for both signatories, just past the
     * widest label line, so the two names line up.
     */
    public static function signatoryNameX(): float
    {
        $widest = max(array_map(
            fn (string $line) => self::textWidth($line, self::HEADING_SIZE, true),
            array_merge(...array_column(self::SIGNATORIES, 'label'))
        ));

        return round(self::MARGIN_LEFT + $widest + self::SIGNATORY_NAME_GAP, 2);
    }

    /** "Date:" for both signatories, on each name's own line: one column,
     *  its blank running to the right edge. */
    public static function signatoryDateLabelX(): float
    {
        return round(self::RULE_RIGHT - self::SIGNATORY_DATE_BLANK - self::BLANK_GAP - self::textWidth('Date:'), 2);
    }

    /** How wide a printed name may run before the Date column. */
    public static function signatoryNameWidth(): float
    {
        return round(self::signatoryDateLabelX() - self::SIGNATORY_DATE_GAP - self::signatoryNameX(), 2);
    }

    /**
     * The soft character cap for one field, from how many rules it has.
     */
    public function charLimitFor(string $key): int
    {
        return count($this->rules()[$key] ?? []) * self::CHARS_PER_LINE ?: self::ANSWER_CHAR_LIMIT;
    }

    /**
     * @return array<string, int>
     */
    public function charLimits(): array
    {
        $limits = [];

        foreach (array_keys($this->rules()) as $key) {
            $limits[$key] = $this->charLimitFor($key);
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
    public function fits(string $key, ?string $text): bool
    {
        $text = trim((string) $text);
        $lines = $this->document()['fields'][$key] ?? null;

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
     *
     * $bold measures Helvetica-Bold, which sets about 5% wider. Measuring a
     * bold label with the regular widths is what printed "Compliance
     * Verification:" and both signatory labels straight into the text after
     * them.
     */
    public static function textWidth(string $text, float $size = self::BODY_SIZE, bool $bold = false): float
    {
        $widths = self::widths($bold);

        $units = 0;

        foreach (self::winAnsi($text) as $code) {
            $units += $widths[$code] ?? 556;
        }

        return $units / 1000 * $size;
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * The masthead text for a form-supplied slot, or null for a title line
     * the form does not have. A one-line title leaves the second slot to the
     * form's subtitle, if it has one (CAST prints "On-the-Job Training (OJT)
     * Program" there); otherwise the slot stays empty.
     */
    private function mastheadSlot(string $slot): ?string
    {
        if ($slot === 'college') {
            return $this->form->collegeLine;
        }

        [, $index] = explode(':', $slot);
        $index = (int) $index;

        if ($index === 1 && ! isset($this->form->titleLines[1])) {
            return $this->form->subtitle;
        }

        return $this->form->titleLines[$index] ?? null;
    }

    /**
     * The question's own text lines, wrapped short of the choice column when
     * it carries a ☐ Yes ☐ No pair.
     *
     * @param  array<string, mixed>  $question
     * @return array<int, string>
     */
    private function questionLines(array $question, float $choiceX): array
    {
        $hasChoice = $question['type'] === ExitInterviewForm::TYPE_YES_NO_TEXT;
        $textWidth = ($hasChoice ? $choiceX - self::CHOICE_GUTTER : self::RULE_RIGHT) - self::TEXT_X;

        return self::wrap($question['text'], array_fill(0, 4, $textWidth));
    }

    /**
     * How far a question runs from its first baseline to its lowest ink — the
     * number the paginator compares against the page's content limit.
     *
     * @param  array<string, mixed>  $question
     */
    private function questionHeight(array $question, float $choiceX): float
    {
        $height = (count($this->questionLines($question, $choiceX)) - 1) * self::LINE;

        if ($question['type'] === ExitInterviewForm::TYPE_SCALE) {
            // One more line for the row of boxes; no answer rules.
            return $height + self::LINE;
        }

        return $height + self::GAP_QUESTION_TO_RULES + ((self::ANSWER_LINES - 1) * self::LINE);
    }

    /**
     * Place one question at $y on $page and return the y of its lowest ink.
     *
     * @param  array<string, mixed>  $question
     * @param  array<int, array<string, mixed>>  $texts
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<int, array<string, mixed>>  $boxes
     * @param  array<string, array<int, array<string, mixed>>>  $fields
     * @param  array<string, array<string, mixed>>  $marks
     */
    private function placeQuestion(array $question, int $page, float $y, float $choiceX, array &$texts, array &$rules, array &$boxes, array &$fields, array &$marks): float
    {
        $lines = $this->questionLines($question, $choiceX);

        $texts[] = self::run($page, self::NUMBER_X, $y, $question['n'].'.');

        foreach ($lines as $index => $line) {
            $texts[] = self::run($page, self::TEXT_X, $y + ($index * self::LINE), $line);
        }

        $y += (count($lines) - 1) * self::LINE;

        // A rating question is a row of boxes on the line under the question,
        // each option's box and label spaced by its own measured width, and
        // no answer rules at all — the paper form gives it none.
        if ($question['type'] === ExitInterviewForm::TYPE_SCALE) {
            $y += self::LINE;
            $x = self::TEXT_X;

            foreach ($question['options'] as $value => $label) {
                $boxes[] = ['page' => $page, 'x' => $x, 'y' => $y - self::BOX_SIZE - 0.2];
                $texts[] = self::run($page, $x + self::BOX_SIZE + self::CHOICE_LABEL_GAP, $y, $label);
                $marks[$question['key'].':'.$value] = self::check($page, $x, $y - self::BOX_SIZE - 0.2);

                $x += self::BOX_SIZE + self::CHOICE_LABEL_GAP + self::textWidth($label) + self::SCALE_OPTION_GAP;
            }

            return $y;
        }

        // A question carrying a ☐ Yes ☐ No pair wraps short of the choice
        // column, so the pair can sit in the SAME place on every one of them
        // instead of trailing whatever length the question happened to be.
        if ($question['type'] === ExitInterviewForm::TYPE_YES_NO_TEXT) {
            foreach (self::choicePair($choiceX) as $option) {
                $boxes[] = ['page' => $page, 'x' => $option['box'], 'y' => $y - self::BOX_SIZE - 0.2];
                $texts[] = self::run($page, $option['label_x'], $y, $option['label']);

                $marks[$question['choice'].':'.$option['value']] = self::check($page, $option['box'], $y - self::BOX_SIZE - 0.2);
            }
        }

        // ── The answer box ──────────────────────────────────────────────
        $first = $y + self::GAP_QUESTION_TO_RULES;
        $lineSpecs = [];

        for ($i = 0; $i < self::ANSWER_LINES; $i++) {
            $ruleY = $first + ($i * self::LINE);
            $rules[] = ['page' => $page, 'x' => self::MARGIN_LEFT, 'y' => $ruleY, 'w' => self::RULE_RIGHT - self::MARGIN_LEFT];

            $inset = 0.0;

            // "Please explain:" rides the FIRST answer line rather than being
            // orphaned onto a line of its own at a different indent — the
            // same treatment the coordinator's "If pending, specify:" already
            // gets.
            if ($i === 0 && ! empty($question['label'])) {
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

        return $first + ((self::ANSWER_LINES - 1) * self::LINE);
    }

    /**
     * The student signature row, the coordinator's block and the two
     * signatories, from the last answer rule at $y. Returns the block's
     * lowest ink. Called once on scratch arrays to learn its height, then
     * once for real.
     *
     * @param  array<int, array<string, mixed>>  $texts
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<int, array<string, mixed>>  $boxes
     * @param  array<string, array<int, array<string, mixed>>>  $fields
     * @param  array<string, array<string, mixed>>  $blanks
     * @param  array<string, array<string, mixed>>  $marks
     * @param  array<string, array<string, mixed>>  $signatories
     */
    private function placeTrailer(int $page, float $y, array &$texts, array &$rules, array &$boxes, array &$fields, array &$blanks, array &$marks, array &$signatories): float
    {
        // ── Student signature ───────────────────────────────────────────
        $y += self::GAP_TO_SIGNATURE;

        foreach ([['Student Signature:', self::MARGIN_LEFT, self::COL_STOP], ['Date:', self::COL2_LABEL_X, self::RULE_RIGHT]] as [$label, $labelX, $stop]) {
            $texts[] = self::run($page, $labelX, $y, $label);
            $blankX = $labelX + self::textWidth($label) + self::BLANK_GAP;
            $rules[] = ['page' => $page, 'x' => $blankX, 'y' => $y + self::BASELINE_LIFT, 'w' => $stop - $blankX];
        }

        // ── Section for the OJT/Internship Coordinator ──────────────────
        $y += self::GAP_TO_HEADING;
        $texts[] = self::heading($page, $y, 'SECTION FOR OJT/INTERNSHIP COORDINATOR');

        $y += self::GAP_HEADING_TO_ROW;
        $texts[] = self::heading($page, $y, 'Compliance Verification:');
        $texts[] = self::run(
            $page,
            self::MARGIN_LEFT + self::textWidth('Compliance Verification:', self::HEADING_SIZE, true) + self::BLANK_GAP,
            $y,
            'Did the student submit all required OJT/INTERNSHIP documents and reports?'
        );

        foreach (self::COMPLIANCE_OPTIONS as $value => $label) {
            $y += self::LINE;
            $boxes[] = ['page' => $page, 'x' => self::TEXT_X, 'y' => $y - self::BOX_SIZE - 0.2];
            $texts[] = self::run($page, self::TEXT_X + self::BOX_SIZE + 5.0, $y, $label);
            $marks['compliance:'.$value] = self::check($page, self::TEXT_X, $y - self::BOX_SIZE - 0.2);
        }

        // "If pending, specify:" — the same inset-label pattern as the
        // questions' "Please explain:", so the label sits ON its first rule
        // rather than orphaned above it.
        $y += self::LINE + self::BASELINE_LIFT;
        $texts[] = self::run($page, self::MARGIN_LEFT, $y - self::BASELINE_LIFT, 'If pending, specify:');
        $inset = self::textWidth('If pending, specify:') + self::LABEL_GAP;

        $pending = [];

        for ($i = 0; $i < 2; $i++) {
            $ruleY = $y + ($i * self::LINE);
            $rules[] = ['page' => $page, 'x' => self::MARGIN_LEFT, 'y' => $ruleY, 'w' => self::RULE_RIGHT - self::MARGIN_LEFT];
            $lineInset = $i === 0 ? $inset : 0.0;
            $pending[] = [
                'page' => $page,
                'x' => self::MARGIN_LEFT + $lineInset,
                'y' => $ruleY,
                'w' => self::RULE_RIGHT - self::MARGIN_LEFT - $lineInset,
            ];
        }

        $fields['pending_detail'] = $pending;
        $y += self::LINE;

        // Remarks: the label rides its FIRST rule, the same inset-label
        // pattern as "If pending, specify:" and "Please explain:", one row
        // below the field above it. As a heading on a line of its own it cost
        // a whole extra line of height, and that height is what the
        // signatories below need to sign in.
        $y += self::LINE + self::BASELINE_LIFT;
        $texts[] = self::heading($page, $y - self::BASELINE_LIFT, 'Remarks:');
        $inset = self::textWidth('Remarks:', self::HEADING_SIZE, true) + self::LABEL_GAP;

        $remarks = [];

        for ($i = 0; $i < self::REMARKS_LINES; $i++) {
            $ruleY = $y + ($i * self::LINE);
            $lineInset = $i === 0 ? $inset : 0.0;
            $rules[] = ['page' => $page, 'x' => self::MARGIN_LEFT, 'y' => $ruleY, 'w' => self::RULE_RIGHT - self::MARGIN_LEFT];
            $remarks[] = ['page' => $page, 'x' => self::MARGIN_LEFT + $lineInset, 'y' => $ruleY, 'w' => self::RULE_RIGHT - self::MARGIN_LEFT - $lineInset];
        }

        $fields['remarks'] = $remarks;
        $y += (self::REMARKS_LINES - 1) * self::LINE;

        // ── Signatories ─────────────────────────────────────────────────
        // A grid: labels at the margin, both printed names in one column,
        // and each Date on its own name's line, both in one column at the
        // right. Above each name, SIGNING_ROOM of clear space for the
        // signature. The names themselves are set by placeSignatories(); this
        // places everything that does not depend on whose they are.
        $nameX = self::signatoryNameX();
        $dateLabelX = self::signatoryDateLabelX();
        $dateBlankX = $dateLabelX + self::textWidth('Date:') + self::BLANK_GAP;

        // The lowest ink above the signer being placed: the last remarks rule
        // for the first, then the previous signer's date rule.
        $above = $y;
        $caption = null;

        foreach (self::SIGNATORIES as $key => $signatory) {
            $y = $above + self::SIGNING_ROOM + self::NAME_CAP_HEIGHT;

            // A two-line label ends on the name's own line, so its first line
            // sits beside the signing room rather than costing height of its
            // own.
            $labelLines = count($signatory['label']);

            foreach ($signatory['label'] as $index => $line) {
                $texts[] = self::heading($page, $y - (($labelLines - 1 - $index) * self::LINE), $line);
            }

            $signatories[$key] = [
                'page' => $page,
                'name' => $signatory['name'],
                'x' => $nameX,
                'baseline' => $y,
                // A printed name, a handwriting line and a caption all stop
                // short of the Date column on the same line.
                'w' => $dateLabelX - self::SIGNATORY_DATE_GAP - $nameX,
                'line_w' => $dateLabelX - self::SIGNATORY_DATE_GAP - $nameX,
                'caption' => $signatory['caption'],
                'caption_stop' => $dateLabelX - self::SIGNATORY_DATE_GAP,
            ];

            $texts[] = self::run($page, $dateLabelX, $y, 'Date:');
            $rules[] = ['page' => $page, 'x' => $dateBlankX, 'y' => $y + self::BASELINE_LIFT, 'w' => self::RULE_RIGHT - $dateBlankX];
            $blanks[$signatory['date']] = [
                'page' => $page,
                'x' => $dateBlankX + 3.0,
                'y' => $y + self::BASELINE_LIFT,
                'w' => self::RULE_RIGHT - $dateBlankX - 3.0,
                'baseline' => $y,
            ];

            $above = $y + self::BASELINE_LIFT;
            $caption = $signatory['caption'];
        }

        // The lowest ink: a caption under the last name ("Dean"), else that
        // line's date rule.
        return $caption !== null ? $y + self::LINE : $above;
    }

    /**
     * A signatory's printed name at the heading size, stepped down just
     * enough to fit its column when a name on record is unusually long, and
     * trimmed at the floor rather than allowed to run off the page. A TYPED
     * name never needs either — signatoryNameFits() refuses it first.
     *
     * @return array{0: string, 1: float}
     */
    private static function fitSignatoryName(string $name, float $width): array
    {
        $size = self::HEADING_SIZE;
        $natural = self::textWidth($name, $size, true);

        if ($natural > $width) {
            $size = max(self::SIGNATORY_MIN_SIZE, floor($size * $width / $natural * 100) / 100);
        }

        while ($name !== '' && self::textWidth($name, $size, true) > $width) {
            $name = rtrim(mb_substr($name, 0, -1));
        }

        return [$name, $size];
    }

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
     * Glyph widths for the face, in 1/1000 em.
     *
     * READ FROM dompdf's OWN SHIPPED `.afm`, NOT from a generated cache, and
     * that is a fix for a total-but-silent measurement failure rather than a
     * tidy-up. This used to read
     * `vendor/dompdf/dompdf/lib/fonts/{face}.afm.json` — a file **dompdf never
     * writes there**. The `.afm.json` files are a CACHE dompdf generates into
     * its configured `font_dir`, and `laravel-dompdf`'s own packaged default
     * points that at `storage_path('fonts')`, whose `.gitignore` is `*` so
     * nothing in it is committed either. The vendor path therefore does not
     * exist in a clean checkout, `widths()` returned `[]`, and every
     * `textWidth()` fell through to its `?? 556` per-character fallback.
     *
     * What that cost, and why nothing caught it: 556 is a plausible-looking
     * number, so nothing errored — every measurement was simply wrong by the
     * ratio of 556 to the real glyph. Regular and bold measured IDENTICALLY,
     * which quietly removes the entire premise of `fits()` (prose sets ~0.45em
     * per character against ALL-CAPS at ~0.60em — indistinguishable at a flat
     * 556); question text over-measured and wrapped early, three lines of it;
     * and the CABM form grew from its measured two pages to three, carrying the
     * coordinator's signature block onto a sheet of its own.
     *
     * The `.afm` is the canonical source that cache is generated FROM, so
     * parsing it keeps the promise that actually matters — the wrap and the
     * render measure the same metrics — while depending only on a file
     * `composer install` guarantees. Verified against dompdf's own generated
     * JSON for both faces: 218 glyphs each, zero mismatches.
     *
     * @return array<int, int>
     */
    private static function widths(bool $bold = false): array
    {
        $face = $bold ? 'Helvetica-Bold' : 'Helvetica';

        if (isset(self::$widths[$face])) {
            return self::$widths[$face];
        }

        $widths = self::parseAfm(base_path("vendor/dompdf/dompdf/lib/fonts/{$face}.afm"));

        // Only if the package's own metrics are somehow absent: dompdf's
        // generated cache holds the parsed form of exactly that file.
        if ($widths === []) {
            $widths = self::cachedWidths($face);
        }

        return self::$widths[$face] = $widths;
    }

    /**
     * Parse an Adobe Font Metrics file's character block — lines shaped
     * `C 32 ; WX 278 ; N space ; B 0 0 0 0 ;` — into code => width.
     *
     * The FIRST entry for a code wins, matching dompdf's own reader: Helvetica
     * lists 160 as a second `space`, and an unencoded glyph carries `C -1`,
     * which is skipped rather than keyed as a negative code.
     *
     * @return array<int, int>
     */
    private static function parseAfm(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $widths = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (strncmp($line, 'C ', 2) !== 0) {
                continue;
            }

            $code = null;
            $width = null;

            foreach (explode(';', $line) as $part) {
                $part = trim($part);

                if (strncmp($part, 'C ', 2) === 0) {
                    $code = (int) trim(substr($part, 2));
                } elseif (strncmp($part, 'WX ', 3) === 0) {
                    $width = (int) trim(substr($part, 3));
                }
            }

            if ($code !== null && $code >= 0 && $width !== null && ! isset($widths[$code])) {
                $widths[$code] = $width;
            }
        }

        return $widths;
    }

    /**
     * dompdf's generated metrics cache, read from whatever `font_dir` the
     * running app actually configured rather than a hardcoded guess.
     *
     * @return array<int, int>
     */
    private static function cachedWidths(string $face): array
    {
        $dir = config('dompdf.options.font_dir');

        if (! is_string($dir) || $dir === '') {
            return [];
        }

        $path = rtrim($dir, '/'.DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$face.'.afm.json';

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded['C'] ?? null)) {
            return [];
        }

        $widths = [];

        foreach ($decoded['C'] as $code => $width) {
            if (is_numeric($code) && (int) $code >= 0) {
                $widths[(int) $code] = (int) $width;
            }
        }

        return $widths;
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
