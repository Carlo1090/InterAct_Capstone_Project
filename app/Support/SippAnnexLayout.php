<?php

namespace App\Support;

/**
 * Measured geometry for the two official CHED/SIPP annexes, taken from the
 * reference .docx files in `docs/reference/` rather than estimated:
 *
 *   Annex "C" — ANNEX C - SIPP REPORT (2).docx  (Annual Report on SIPP)
 *   Annex "D" — ANNEX D - SIPP REPORT (1).docx  (HTE & Student Interns List)
 *
 * The numbers below are read straight out of `word/document.xml`. Word stores
 * lengths in TWIPS (1/20 pt), so every value here is the reference's own twip
 * figure divided by 20 — nothing is rounded to a "nice" number, for the same
 * reason the weekly activity log and the exit interview keep their raw
 * measurements.
 *
 * WHAT THE REFERENCE ACTUALLY SAYS, and what the blades used to do instead:
 *
 * | measurement   | reference                    | before                     |
 * |---------------|------------------------------|----------------------------|
 * | page          | 18711 x 12242 twips LANDSCAPE| Annex C: dompdf's A4        |
 * |               | = 935.55 x 612.1 pt          |   PORTRAIT default (no      |
 * |               |                              |   setPaper call at all)     |
 * |               |                              | Annex D: A4 landscape       |
 * | margins       | 1440 twips = 72pt all round  | 2cm/1.8cm and 1.6cm/1.4cm   |
 * | body type     | 12pt (w:sz 24 half-points)   | 12*px* ~= 9pt — a quarter   |
 * |               |                              |   too small, in a serif     |
 * | rules         | w:sz 4 eighths = 0.5pt       | 1px                         |
 * | cell padding  | 108 twips = 5.4pt L/R, 0 T/B | 6-8px all round             |
 *
 * The page is Philippine "long bond" (8.5in x 13in, Folio/F4) turned
 * LANDSCAPE — the same sheet the exit interview prints on, rotated. Getting
 * this wrong is not cosmetic: on A4 portrait the three-column Annex C table is
 * squeezed into 462pt of usable width instead of 792pt, so every column is
 * roughly 40% too narrow and the text wraps to a shape the real form never has.
 *
 * TYPE IS A SUBSTITUTION, AND DELIBERATELY SO. The reference's theme font is
 * **Aptos**, Microsoft's current default — proprietary, absent from the Linux
 * container, and not redistributable. Carlito already ships in this repo (SIL
 * OFL, metric-compatible with Calibri) and is registered for the weekly
 * activity log, so it is the substitute here too: same nominal 12pt, same
 * humanist-sans colour on the page. This is NOT claimed to be a metric match
 * for Aptos the way Carlito genuinely is for Calibri — it is the closest
 * licensable face already in the project, and the blades' font stacks fall
 * back to Helvetica if the file is ever missing.
 */
class SippAnnexLayout
{
    /**
     * Portrait box; both annexes are rendered with `'landscape'`, which is what
     * swaps these into a 935.55 x 612.1 pt sheet. Passing the already-swapped
     * box AND 'landscape' would rotate it back.
     */
    public const PAGE_SHORT_EDGE = 612.1;   // 12242 twips — 8.5in

    public const PAGE_LONG_EDGE = 935.55;   // 18711 twips — 13in

    /** 1440 twips on every side. */
    public const MARGIN = 72.0;

    /** 935.55 - 72 - 72. The table widths below sit inside this. */
    public const CONTENT_WIDTH = 791.55;

    /** w:sz 4 = 4/8 pt. */
    public const RULE = 0.5;

    /** w:tblCellMar left/right 108 twips; top/bottom are 0 in the reference. */
    public const CELL_PAD_X = 5.4;

    /** w:sz 24 half-points. */
    public const BODY_PT = 12.0;

    /**
     * Annex C's three columns: 6769 / 5225 / 3513 twips.
     *
     * @var array<int, float>
     */
    public const ANNEX_C_COLUMNS = [338.45, 261.25, 175.65];

    /**
     * Annex D's five columns: 4957 / 3827 / 1797 / 1888 / 2753 twips.
     *
     * @var array<int, float>
     */
    public const ANNEX_D_COLUMNS = [247.85, 191.35, 89.85, 94.4, 137.65];

    /**
     * The width to WRITE on a cell, given the column's true width.
     *
     * dompdf treats a cell width as content-box and adds padding on top, so a
     * column measured at 338.45pt must be written as 338.45 - (2 x 5.4). The
     * group info sheet's blade documents the same adjustment; getting it wrong
     * widens every table by its own padding.
     */
    public static function contentWidth(float $columnWidth): float
    {
        return round($columnWidth - (2 * self::CELL_PAD_X), 2);
    }

    /**
     * @param  array<int, float>  $columns
     * @return array<int, float>
     */
    public static function contentWidths(array $columns): array
    {
        return array_map(static fn (float $width) => self::contentWidth($width), $columns);
    }

    /**
     * @param  array<int, float>  $columns
     */
    public static function tableWidth(array $columns): float
    {
        return round(array_sum($columns), 2);
    }
}
