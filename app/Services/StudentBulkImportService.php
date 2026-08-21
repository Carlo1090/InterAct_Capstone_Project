<?php

namespace App\Services;

use App\Exceptions\BulkImportFileException;
use App\Imports\StudentBulkImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Parses and validates a coordinator-uploaded student roster spreadsheet.
 * Used identically by both the preview and confirm steps of bulk import
 * (BulkStudentImportController) so the two can never disagree about which
 * rows are valid — confirm always re-derives from the file itself rather
 * than trusting a client-supplied "these rows passed" list.
 */
class StudentBulkImportService
{
    /**
     * Kept small enough to finish inside one synchronous HTTP request — there
     * is no queue worker in this deployment, so a file over this is rejected
     * upfront with instructions to split it, rather than partially processed
     * or left to time out mid-request.
     */
    public const MAX_ROWS = 100;

    /**
     * Slugged header keys the file MUST carry. Middle Name and Sex are
     * deliberately absent — they are the two optional columns.
     *
     * @var list<string>
     */
    private const REQUIRED_COLUMNS = ['first_name', 'student_id_number', 'email'];

    /**
     * Header text as the coordinator sees it, for the error message. A missing
     * column has to be named the way it appears in their spreadsheet, not as
     * the internal slug.
     *
     * @var array<string, string>
     */
    private const COLUMN_LABELS = [
        'first_name' => 'First Name',
        'last_name' => 'Family Name',
        'student_id_number' => 'Student ID Number',
        'email' => 'Email',
    ];

    /**
     * @return array{rows: list<array>, tooMany: bool, count: int}
     *
     * @throws BulkImportFileException when the file itself cannot be used
     */
    public function parseAndValidate(UploadedFile $file): array
    {
        $import = new StudentBulkImport;

        try {
            Excel::import($import, $file);
        } catch (Throwable $e) {
            // PhpSpreadsheet throws a wide family of reader exceptions for a
            // truncated download, a .xls that is really something else, or a
            // password-protected workbook. Uncaught, these were a raw 500,
            // which tells the coordinator nothing and reads as the app being
            // broken rather than the file. Re-thrown as the one type the
            // controller answers with a 422.
            report($e);

            throw new BulkImportFileException(
                'This file could not be read. Make sure it is a valid .xlsx, .xls or .csv saved from the template, and that it is not password-protected.'
            );
        }

        $rawRows = ($import->rows ?? collect())
            ->filter(fn ($row) => $this->rowHasAnyData($row))
            ->values();

        if ($rawRows->isEmpty()) {
            throw new BulkImportFileException(
                'No student rows were found in this file. Check that the roster is on the FIRST sheet, that row 1 holds the column headings, and that there is at least one student listed below them.'
            );
        }

        $this->assertRequiredColumns($import->headings);

        if ($rawRows->count() > self::MAX_ROWS) {
            return ['rows' => [], 'tooMany' => true, 'count' => $rawRows->count()];
        }

        $parsed = $rawRows->map(fn ($row, int $index) => $this->extract($row, $index))->values();

        // Counted across the WHOLE file first (not row-by-row) so BOTH rows
        // sharing a duplicate ID number or email get flagged, not just the
        // second occurrence — a DB-uniqueness check alone can't catch this,
        // since neither row exists in the database yet.
        $idCounts = $parsed->countBy('student_id_number');
        $emailCounts = $parsed->countBy(fn (array $row) => mb_strtolower($row['email']));

        $rows = $parsed->map(fn (array $row) => $this->validateRow($row, $idCounts, $emailCounts))->values()->all();

        return ['rows' => $rows, 'tooMany' => false, 'count' => count($rows)];
    }

    /**
     * A renamed or absent heading surfaced as the SAME error on every single
     * row — 40 rows each reporting that Email is required — with nothing
     * anywhere pointing at the actual cause, which is one cell in row 1.
     * Checked once, against the file, and named.
     *
     * @param  list<string>  $headings
     *
     * @throws BulkImportFileException
     */
    private function assertRequiredColumns(array $headings): void
    {
        // Family Name is satisfied by either heading, matching extract().
        $hasLastName = in_array('family_name', $headings, true) || in_array('last_name', $headings, true);

        $missing = collect(self::REQUIRED_COLUMNS)
            ->reject(fn (string $column) => in_array($column, $headings, true))
            ->when(! $hasLastName, fn (Collection $columns) => $columns->push('last_name'))
            ->map(fn (string $column) => self::COLUMN_LABELS[$column] ?? $column)
            ->values();

        if ($missing->isEmpty()) {
            return;
        }

        throw new BulkImportFileException(
            'This file is missing the '.$missing->join(', ', ' and ').' column'.
            ($missing->count() === 1 ? '' : 's').
            '. Row 1 must hold the headings exactly as they appear in the template: First Name, Middle Name, Family Name, Sex, Student ID Number, Email.'
        );
    }

    private function rowHasAnyData(Collection $row): bool
    {
        return $row->filter(fn ($value) => trim((string) $value) !== '')->isNotEmpty();
    }

    private function extract(Collection $row, int $index): array
    {
        return [
            // +2: the heading row is row 1, so the first data row is row 2 —
            // matching what the coordinator would count in Excel itself.
            'row' => $index + 2,
            'first_name' => trim((string) $row->get('first_name', '')),
            'middle_name' => trim((string) $row->get('middle_name', '')) ?: null,
            'last_name' => trim((string) ($row->get('family_name') ?? $row->get('last_name') ?? '')),
            'sex' => trim((string) $row->get('sex', '')),
            'student_id_number' => trim((string) $row->get('student_id_number', '')),
            'email' => trim((string) $row->get('email', '')),
        ];
    }

    private function validateRow(array $data, Collection $idCounts, Collection $emailCounts): array
    {
        $errors = [];

        if ($data['first_name'] === '') {
            $errors[] = 'First Name is required.';
        }

        if ($data['last_name'] === '') {
            $errors[] = 'Family Name is required.';
        }

        if ($data['student_id_number'] === '') {
            $errors[] = 'Student ID Number is required.';
        } elseif ($idCounts->get($data['student_id_number'], 0) > 1) {
            $errors[] = 'This Student ID Number appears more than once in this file.';
        } elseif (
            User::where('student_id_number', $data['student_id_number'])->exists()
            || User::where('username', $data['student_id_number'])->exists()
        ) {
            $errors[] = 'This Student ID Number is already in use.';
        }

        $emailKey = mb_strtolower($data['email']);

        if ($data['email'] === '') {
            $errors[] = 'Email is required.';
        } elseif (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email format is invalid.';
        } elseif ($emailCounts->get($emailKey, 0) > 1) {
            $errors[] = 'This Email appears more than once in this file.';
        } elseif (User::whereRaw('LOWER(email) = ?', [$emailKey])->exists()) {
            // Compared case-INSENSITIVELY, matching the in-file duplicate check
            // above. A plain where() is case-sensitive under SQLite, so an
            // address differing only in case slipped past validation and died
            // on the unique index instead, surfacing as the generic "Could not
            // be created" rather than naming the real clash.
            $errors[] = 'This Email is already in use.';
        }

        $sex = null;

        if ($data['sex'] !== '') {
            // M/F accepted alongside the full words: a registrar export
            // routinely carries the abbreviation, and rejecting it made the
            // coordinator hand-edit every row of an otherwise valid file.
            $sex = match (mb_strtolower($data['sex'])) {
                'male', 'm' => 'male',
                'female', 'f' => 'female',
                default => null,
            };

            if ($sex === null) {
                $errors[] = 'Sex must be Male or Female.';
            }
        }

        return [
            ...$data,
            'sex' => $sex,
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }
}
