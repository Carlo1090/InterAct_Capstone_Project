<?php

namespace App\Http\Controllers\Coordinator;

use App\Exceptions\BulkImportFileException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\BulkImportStudentsRequest;
use App\Models\Batch;
use App\Models\StudentProfile;
use App\Models\SystemLog;
use App\Models\User;
use App\Notifications\NewAccountCredentials;
use App\Services\EnrollmentService;
use App\Services\StudentBulkImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bulk student account creation from an Excel/CSV roster — replaces typing
 * each student's account by hand one at a time
 * (EnrollmentController::createAccount). This is account creation only, NOT
 * enrollment: exactly like the manual flow, each created student gets a
 * DRAFT info sheet pointing at the intended batch and remains NOT-ENROLLED
 * until they submit it and a coordinator Accepts. See
 * StudentBulkImportService for row parsing/validation and
 * NewAccountCredentials for the welcome email.
 */
class BulkStudentImportController extends Controller
{
    /**
     * Wall-clock budget per row, covering the account write plus one inline
     * SMTP send. Gmail runs roughly 1-2s per message and nothing here is
     * queued (QUEUE_CONNECTION=sync), so a flat cap could not stretch to
     * cover a full file.
     */
    private const SECONDS_PER_ROW = 3;

    /** Fixed overhead for parsing the spreadsheet before the loop starts. */
    private const BASE_SECONDS = 60;

    /**
     * A send that FAILS after at least this long is a mail server that is not
     * answering (a timeout), not a refused recipient — which fails in well
     * under a second. Once one has been seen, the rest of the request stops
     * trying: every further send would burn the same timeout, and twenty of
     * them would push the request past the proxy's limit on their own.
     */
    private const UNREACHABLE_MAIL_SECONDS = 5;

    /** Tripped by the first slow mail failure in this request; see above. */
    private bool $mailUnreachable = false;

    public function __construct(
        private readonly StudentBulkImportService $importer,
        private readonly EnrollmentService $enrollments,
    ) {}

    /**
     * Parse + validate every row and return a per-row status table. Nothing
     * is persisted here — this is what the coordinator reviews before
     * Confirm actually creates anything.
     */
    public function preview(BulkImportStudentsRequest $request): JsonResponse
    {
        try {
            $result = $this->importer->parseAndValidate($request->file('file'));
        } catch (BulkImportFileException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['tooMany']) {
            return $this->tooManyResponse($result['count']);
        }

        $rows = collect($result['rows']);

        return response()->json([
            'rows' => $rows->values(),
            'valid_count' => $rows->where('status', StudentBulkImportService::STATUS_READY)->count(),
            'invalid_count' => $rows->where('status', StudentBulkImportService::STATUS_INVALID)->count(),
            // Students already imported (same ID and email) — skipped, but not
            // something to fix. Counted apart so a re-upload reads calmly.
            'existing_count' => $rows->where('status', StudentBulkImportService::STATUS_EXISTING)->count(),
        ]);
    }

    /**
     * Re-parses and re-validates the SAME uploaded file from scratch — never
     * trusts a client-declared "these rows are valid" list, since the file
     * could have changed or another import could have consumed an ID number
     * since preview. Each valid row is created independently (its own
     * create + profile update + notify), so one row failing can't roll back
     * or block rows already created before it, and a mail failure doesn't
     * undo the account it belongs to.
     *
     * ONE REQUEST CREATES ONE SLICE (`offset` + `limit`), and the SPA walks
     * the file slice by slice. The whole file in one request was a real
     * failure, not a theoretical one: every row sends its email inline, a
     * full file at Gmail's pace runs past two minutes, and Vercel's rewrite
     * proxy gives up at 120s regardless of PHP's own limit. PHP then kept
     * creating accounts with nobody listening, the one-time credentials table
     * never arrived, and a retry reported every row "already in use". The
     * whole file is still parsed and validated on every slice — that is what
     * catches a duplicate ID between two rows in different slices — but only
     * the slice's rows are acted on. Rows an earlier slice created read as
     * "already in use" on later parses, which is harmless: they are outside
     * this slice. Omitting both parameters processes the whole file, as before.
     */
    public function confirm(BulkImportStudentsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $batch = Batch::where('id', $validated['batch_id'])
            ->where('program_id', $validated['program_id'])
            ->first();

        abort_unless($batch !== null, 422, 'The selected batch does not belong to that program.');

        try {
            $result = $this->importer->parseAndValidate($request->file('file'));
        } catch (BulkImportFileException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['tooMany']) {
            return $this->tooManyResponse($result['count']);
        }

        $total = count($result['rows']);
        $offset = (int) ($validated['offset'] ?? 0);
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : $total;
        $slice = array_slice($result['rows'], $offset, $limit);

        // Sized to the work actually in front of us, not a flat 120s, so PHP
        // never kills a slice MID-LOOP (which left accounts created and no
        // response carrying their passwords). This governs PHP only — the
        // proxy's own limit is what the slicing above exists for.
        set_time_limit(self::BASE_SECONDS + (count($slice) * self::SECONDS_PER_ROW));

        $outcomes = [];
        $createdCount = 0;

        foreach ($slice as $row) {
            if ($row['status'] === StudentBulkImportService::STATUS_EXISTING) {
                $outcomes[] = [...$row, 'outcome' => 'already_exists', 'temporary_password' => null];

                continue;
            }

            if (! $row['valid']) {
                $outcomes[] = [...$row, 'outcome' => 'skipped_invalid', 'temporary_password' => null];

                continue;
            }

            try {
                $outcomes[] = $this->createOne($row, $batch, (int) $validated['program_id']);
                $createdCount++;
            } catch (Throwable $e) {
                report($e);
                $outcomes[] = [
                    ...$row,
                    'status' => StudentBulkImportService::STATUS_INVALID,
                    'valid' => false,
                    'errors' => ['Could not be created — please retry this row in a new upload.'],
                    'outcome' => 'skipped_invalid',
                    'temporary_password' => null,
                ];
            }
        }

        if ($createdCount > 0) {
            SystemLog::record('Bulk Imported Students', "Bulk imported {$createdCount} student account(s) into {$batch->name}");
        }

        return response()->json([
            'results' => $outcomes,
            'created_count' => $createdCount,
            'total_rows' => $total,
            // null once this slice reached the end of the file.
            'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
        ]);
    }

    private function tooManyResponse(int $count): JsonResponse
    {
        return response()->json([
            'message' => "This file has {$count} rows. Please split it into batches of ".StudentBulkImportService::MAX_ROWS.' or fewer.',
        ], 422);
    }

    private function createOne(array $row, Batch $batch, int $programId): array
    {
        $name = collect([$row['first_name'], $row['middle_name'], $row['last_name']])->filter()->implode(' ');
        // Letters and digits only: this password is read off a printout or a
        // screen and typed on a phone, and `^\c5jW52#FQ*` was the kind of thing
        // the default symbol set produced. Twelve alphanumerics are still ~71
        // bits, and the student must replace it on first sign-in anyway.
        $temporaryPassword = Str::password(12, symbols: false);

        // ONE ROW IS ONE TRANSACTION, and the row's own catch block above is
        // what keeps that from becoming a whole-file transaction. Without it a
        // failure in the profile or info-sheet write left the users row
        // committed and nothing else: the response said "Could not be created
        // — please retry this row in a new upload", but the retry then failed
        // validation with "This Student ID Number is already in use", so the
        // coordinator was told to do the one thing that could not work, and
        // the student was left with an account carrying no draft info sheet
        // (and so no intended batch, which is what Accept enrolls from).
        $user = DB::transaction(function () use ($row, $batch, $programId, $name, $temporaryPassword) {
            $user = User::create([
                'name' => $name,
                'username' => $row['student_id_number'],
                'email' => $row['email'],
                'password' => $temporaryPassword,
                'role' => 'student',
                'program_id' => $programId,
                'student_id_number' => $row['student_id_number'],
                'is_active' => true,
                'must_change_password' => true,
            ]);

            // The UserObserver already auto-created the profile on user create,
            // so set middle_name/sex explicitly (firstOrCreate would no-op on
            // the existing row and never persist them) — same pattern as the
            // manual createAccount flow.
            $profile = StudentProfile::firstOrCreate(
                ['user_id' => $user->id],
                ['student_id_number' => $user->student_id_number],
            );
            $profile->update([
                'middle_name' => $row['middle_name'],
                'sex' => $row['sex'],
            ]);

            $this->enrollments->scaffoldIntendedSheet($user, $batch, [
                'first_name' => $row['first_name'],
                'middle_name' => $row['middle_name'],
                'last_name' => $row['last_name'],
                'sex' => $row['sex'],
            ]);

            return $user;
        });

        // Deliberately OUTSIDE the transaction: a dead SMTP credential must
        // never roll back an account that is otherwise complete. The row
        // reports created_email_failed and the password is handed back in the
        // one-time credentials table instead.
        $emailed = false;

        if (! $this->mailUnreachable) {
            $startedAt = microtime(true);

            try {
                $user->notify(new NewAccountCredentials($user->username, $temporaryPassword));
                $emailed = true;
            } catch (Throwable $e) {
                report($e);
                $this->mailUnreachable = microtime(true) - $startedAt >= self::UNREACHABLE_MAIL_SECONDS;
            }
        }

        return [
            ...$row,
            'outcome' => $emailed ? 'created_and_emailed' : 'created_email_failed',
            'temporary_password' => $temporaryPassword,
        ];
    }
}
