<?php

namespace App\Services;

use App\Exceptions\BulkImportFileException;
use App\Imports\StudentBulkImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * The most rows one confirm request may create. A full file is walked in
     * slices because every row sends its welcome email inline, and the proxy in
     * front of the deployed API (Vercel's rewrite) cuts a request off at 120s
     * whatever PHP's own limit says — see BulkStudentImportController::confirm.
     */
    public const MAX_ROWS_PER_REQUEST = 25;

    /** A row that will become a new account on confirm. */
    public const STATUS_READY = 'ready';

    /** A row with something to fix; skipped on confirm. */
    public const STATUS_INVALID = 'invalid';

    /** A student who already has an account with this ID and email; skipped on confirm, nothing to fix. */
    public const STATUS_EXISTING = 'existing';

    /**
     * Column limits, so a value the database cannot hold is refused at PREVIEW
     * with a reason, instead of previewing "Ready" and then failing at confirm
     * with "Could not be created" — which, under MySQL's strict mode, no retry
     * could ever fix. Each name part matches the info sheet's own field; the
     * joined name is users.name (150); the ID is student_profiles'
     * student_id_number (30).
     */
    private const MAX_NAME_PART = 100;

    private const MAX_FULL_NAME = 150;

    private const MAX_ID_NUMBER = 30;

    private const MAX_EMAIL = 255;

    /**
     * RFC 2606 reserves these for documentation; nothing at them can receive
     * the credentials email. In practice the only way one reaches an upload is
     * the template's own sample row left in by mistake — which, unflagged,
     * created a real account for "Juan Dela Cruz" and consumed his sample ID.
     */
    private const PLACEHOLDER_EMAIL_DOMAIN = '/(^|\.)example\.(com|net|org)$/';

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

        $taken = $this->takenIdentifiers($parsed);

        $rows = $parsed->map(fn (array $row) => $this->validateRow($row, $idCounts, $emailCounts, $taken))->values()->all();

        return ['rows' => $rows, 'tooMany' => false, 'count' => count($rows)];
    }

    /**
     * Every ID number and email in the file that already belongs to an account,
     * read in two queries for the whole file rather than three per row. The
     * file is re-parsed on EVERY confirm slice, so per-row lookups cost a full
     * file's worth of queries on each one.
     *
     * `owners` maps an ID number to the account holding it (as its
     * student_id_number or its username — bulk import uses the ID as both), so
     * validateRow() can tell "this student was already imported" from "this
     * ID belongs to somebody else".
     *
     * @return array{owners: Collection<string, array{role: string, email: ?string}>, emails: Collection<string, true>}
     */
    private function takenIdentifiers(Collection $parsed): array
    {
        $ids = $parsed->pluck('student_id_number')->filter()->unique()->values()->all();
        $emails = $parsed->pluck('email')->filter()->map(fn (string $email) => mb_strtolower($email))->unique()->values()->all();

        $owners = collect();

        if ($ids !== []) {
            User::query()
                ->where(fn ($query) => $query->whereIn('student_id_number', $ids)->orWhereIn('username', $ids))
                ->get(['id', 'role', 'username', 'student_id_number', 'email'])
                // A student_id_number match is the stronger claim, so it wins
                // over an account that merely has that string as a username.
                ->sortBy(fn (User $user) => in_array($user->student_id_number, $ids, true) ? 0 : 1)
                ->each(function (User $user) use ($owners, $ids) {
                    foreach ([$user->student_id_number, $user->username] as $key) {
                        if ($key !== null && in_array($key, $ids, true) && ! $owners->has($key)) {
                            $owners->put($key, [
                                'role' => $user->role,
                                'email' => $user->email !== null ? mb_strtolower($user->email) : null,
                            ]);
                        }
                    }
                });
        }

        // Compared case-INSENSITIVELY, matching the in-file duplicate check. A
        // plain where() is case-sensitive under SQLite, so an address differing
        // only in case slipped past validation and died on the unique index
        // instead, surfacing as the generic "Could not be created" rather than
        // naming the real clash.
        $takenEmails = $emails === []
            ? collect()
            : User::query()
                ->whereIn(DB::raw('LOWER(email)'), $emails)
                ->selectRaw('LOWER(email) as email_key')
                ->pluck('email_key')
                ->mapWithKeys(fn (string $email) => [$email => true]);

        return ['owners' => $owners, 'emails' => $takenEmails];
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

    /**
     * @param  array{owners: Collection, emails: Collection}  $taken
     */
    private function validateRow(array $data, Collection $idCounts, Collection $emailCounts, array $taken): array
    {
        $errors = [];
        $emailKey = mb_strtolower($data['email']);

        // The SAME student, already imported: a student account holds this ID
        // AND carries this email. Re-uploading a roster after a dropped
        // connection, or a file that overlaps last week's, is routine — those
        // rows used to come back as red "already in use" errors, which read as
        // something to fix when there was nothing to do. An ID held by any
        // other account (or with a different email) is still a real clash.
        $owner = $data['student_id_number'] !== '' ? $taken['owners']->get($data['student_id_number']) : null;
        $alreadyImported = $owner !== null
            && $owner['role'] === 'student'
            && $emailKey !== ''
            && $owner['email'] === $emailKey;

        if ($data['first_name'] === '') {
            $errors[] = 'First Name is required.';
        }

        if ($data['last_name'] === '') {
            $errors[] = 'Family Name is required.';
        }

        foreach (['first_name' => 'First Name', 'middle_name' => 'Middle Name', 'last_name' => 'Family Name'] as $field => $label) {
            if (mb_strlen((string) $data[$field]) > self::MAX_NAME_PART) {
                $errors[] = "{$label} may not be longer than ".self::MAX_NAME_PART.' characters.';
            }
        }

        $fullName = collect([$data['first_name'], $data['middle_name'], $data['last_name']])->filter()->implode(' ');

        if (mb_strlen($fullName) > self::MAX_FULL_NAME) {
            $errors[] = 'The full name may not be longer than '.self::MAX_FULL_NAME.' characters altogether.';
        }

        if ($data['student_id_number'] === '') {
            $errors[] = 'Student ID Number is required.';
        } elseif (mb_strlen($data['student_id_number']) > self::MAX_ID_NUMBER) {
            $errors[] = 'Student ID Number may not be longer than '.self::MAX_ID_NUMBER.' characters.';
        } elseif ($idCounts->get($data['student_id_number'], 0) > 1) {
            $errors[] = 'This Student ID Number appears more than once in this file.';
        } elseif ($owner !== null && ! $alreadyImported) {
            $errors[] = 'This Student ID Number is already in use.';
        }

        if ($data['email'] === '') {
            $errors[] = 'Email is required.';
        } elseif (mb_strlen($data['email']) > self::MAX_EMAIL) {
            $errors[] = 'Email may not be longer than '.self::MAX_EMAIL.' characters.';
        } elseif (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email format is invalid.';
        } elseif (preg_match(self::PLACEHOLDER_EMAIL_DOMAIN, mb_substr($emailKey, mb_strrpos($emailKey, '@') + 1))) {
            $errors[] = "This is a placeholder address (example.com) that cannot receive email — if this is the template's sample row, delete it.";
        } elseif ($emailCounts->get($emailKey, 0) > 1) {
            $errors[] = 'This Email appears more than once in this file.';
        } elseif ($taken['emails']->has($emailKey) && ! $alreadyImported) {
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

        // An already-imported row creates nothing, so it is never `valid` — but
        // it is not a problem either, and gets its own status rather than an
        // error. Only when the row is otherwise clean: a duplicate inside the
        // file still needs fixing whoever owns the ID.
        $status = match (true) {
            $errors !== [] => self::STATUS_INVALID,
            $alreadyImported => self::STATUS_EXISTING,
            default => self::STATUS_READY,
        };

        return [
            ...$data,
            // As typed, so a "rows to fix" download hands the coordinator back
            // their own value ("Other") rather than the normalised null.
            'sex_input' => $data['sex'],
            'sex' => $sex,
            'status' => $status,
            'valid' => $status === self::STATUS_READY,
            'errors' => $errors,
        ];
    }
}
