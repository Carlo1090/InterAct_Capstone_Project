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
 */
class StudentBulkImport implements ToCollection, WithHeadingRow
{
    public Collection $rows;

    public function collection(Collection $rows): void
    {
        $this->rows = $rows;
    }
}
