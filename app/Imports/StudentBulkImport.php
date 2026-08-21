<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Reads a student-roster spreadsheet keyed by its header row (maatwebsite
 * slugs "Student ID Number" to student_id_number, etc.), so the template's
 * column ORDER doesn't matter — only the header text does. Row-level
 * validation lives in StudentBulkImportService, not here; this class only
 * parses.
 *
 * THIS CLASS PICKS ONE SHEET OUT OF THE WORKBOOK, AND THAT IS ITS WHOLE JOB
 * BEYOND PARSING. Reader::loadSpreadsheet() does
 * `array_fill(0, getSheetCount(), $import)` for any import that is not
 * WithMultipleSheets — it hands EVERY worksheet to this same object, so
 * collection() fires once per sheet. Assigning $this->rows unconditionally
 * therefore let the LAST sheet overwrite the roster: a workbook carrying an
 * "Instructions" tab, or merely the empty trailing "Sheet2" that Excel and
 * LibreOffice add by default, imported ZERO rows and reported it as a
 * successful preview of an empty file. Confirmed against a real .xlsx; it
 * escaped review because every test in BulkStudentImportTest uploads CSV, and
 * a CSV has no worksheets for the overwrite to happen across.
 *
 * The obvious fix — implementing WithMultipleSheets and returning
 * `[0 => $this]` — does NOT work here and must not be reintroduced. An import
 * that returns itself from sheets() sends
 * ColumnCollection::requiresStyleInformation() into unbounded recursion
 * (vendor/maatwebsite/excel/src/Columns/ColumnCollection.php:104-109 walks
 * sheets() looking for WithColumns), which exhausts memory before a single
 * row is read. Selecting the sheet here depends on no reader internals at all.
 */
class StudentBulkImport implements ToCollection, WithHeadingRow
{
    /**
     * The column that identifies a sheet as the roster rather than a notes or
     * instructions tab. Required, and distinctive enough that no other kind of
     * sheet carries it by accident.
     */
    private const MARKER_COLUMN = 'student_id_number';

    public Collection $rows;

    /**
     * The slugged header keys actually found on the chosen sheet, so the
     * service can tell "you are missing an Email column" from "every one of
     * your 40 rows happens to have a blank email" — which otherwise look
     * identical, since an absent column reads as an absent value on every
     * single row.
     *
     * @var list<string>
     */
    public array $headings = [];

    /** Whether the sheet already held is a real roster, not just a fallback. */
    private bool $matchedRoster = false;

    public function collection(Collection $rows): void
    {
        // A blank sheet never wins, so a trailing empty "Sheet2" cannot
        // displace the roster that came before it.
        if ($rows->isEmpty()) {
            return;
        }

        $headings = array_map(strval(...), collect($rows->first())->keys()->all());
        $isRoster = in_array(self::MARKER_COLUMN, $headings, true);

        // First real roster wins outright.
        if ($this->matchedRoster) {
            return;
        }

        // Otherwise the first non-empty sheet is held only provisionally — a
        // later sheet that actually looks like the roster replaces it. That is
        // what makes a workbook whose first tab is instructions still import,
        // rather than failing on a sheet the coordinator never meant us to
        // read. `isset` (not a null check) because $rows is a typed property
        // with no default: before the first assignment it is uninitialized.
        if (isset($this->rows) && ! $isRoster) {
            return;
        }

        $this->rows = $rows;
        $this->headings = $headings;
        $this->matchedRoster = $isRoster;
    }
}
