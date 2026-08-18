<?php

namespace App\Services;

use App\Imports\StudentBulkImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

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
     * @return array{rows: list<array>, tooMany: bool, count: int}
     */
    public function parseAndValidate(UploadedFile $file): array
    {
        $import = new StudentBulkImport;
        Excel::import($import, $file);

        $rawRows = ($import->rows ?? collect())
            ->filter(fn ($row) => $this->rowHasAnyData($row))
            ->values();

        if ($rawRows->count() > self::MAX_ROWS) {
            return ['rows' => [], 'tooMany' => true, 'count' => $rawRows->count()];
        }

        $parsed = $rawRows->map(fn ($row, int $index) => $this->extract($row, $index))->values();

        // Counted across the WHOLE file first (not row-by-row) so BOTH rows
        // sharing a duplicate ID number or email get flagged, not just the
        // second occurrence — a DB-uniqueness check alone can't catch this,
        // since neither row exists in the database yet.
        $idCounts = $parsed->countBy('student_id_number');
        $emailCounts = $parsed->countBy(fn (array $row) => strtolower($row['email']));

        $rows = $parsed->map(fn (array $row) => $this->validateRow($row, $idCounts, $emailCounts))->values()->all();

        return ['rows' => $rows, 'tooMany' => false, 'count' => count($rows)];
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

        $emailKey = strtolower($data['email']);

        if ($data['email'] === '') {
            $errors[] = 'Email is required.';
        } elseif (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email format is invalid.';
        } elseif ($emailCounts->get($emailKey, 0) > 1) {
            $errors[] = 'This Email appears more than once in this file.';
        } elseif (User::where('email', $data['email'])->exists()) {
            $errors[] = 'This Email is already in use.';
        }

        $sex = null;

        if ($data['sex'] !== '') {
            $lower = strtolower($data['sex']);

            if (in_array($lower, ['male', 'female'], true)) {
                $sex = $lower;
            } else {
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
