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
            'valid_count' => $rows->where('valid', true)->count(),
            'invalid_count' => $rows->where('valid', false)->count(),
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

        // Sized to the work actually in front of us, not a flat 120s. A full
        // 100-row file sends 100 messages inline; at Gmail's pace that alone
        // can pass two minutes, and PHP then killed the request MID-LOOP —
        // leaving the accounts already created but returning no response, so
        // the one-time credentials table was lost for every student in the
        // file and each one needed an individual Resend. Note this governs
        // PHP only: a reverse proxy in front of the app keeps its own timeout.
        set_time_limit(self::BASE_SECONDS + (count($result['rows']) * self::SECONDS_PER_ROW));

        $outcomes = [];
        $createdCount = 0;

        foreach ($result['rows'] as $row) {
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
        $temporaryPassword = Str::password(12);

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
        $emailed = true;

        try {
            $user->notify(new NewAccountCredentials($user->username, $temporaryPassword));
        } catch (Throwable $e) {
            report($e);
            $emailed = false;
        }

        return [
            ...$row,
            'outcome' => $emailed ? 'created_and_emailed' : 'created_email_failed',
            'temporary_password' => $temporaryPassword,
        ];
    }
}
