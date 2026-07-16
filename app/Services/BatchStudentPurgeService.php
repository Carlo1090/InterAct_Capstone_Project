<?php

namespace App\Services;

use App\Models\BatchStudent;
use App\Models\User;
use Carbon\Carbon;

/**
 * Permanently deletes any batch_students row archived (BatchRosterController::archive())
 * 30+ days ago. Shared by the nightly schedule
 * (App\Console\Commands\PurgeArchivedBatchStudents) and the admin demo-trigger
 * endpoint so the purge logic lives in exactly one place.
 *
 * No other table has a foreign key referencing batch_students.id (journal
 * entries and weekly logs key off student_id + batch_id directly), so this
 * hard delete cannot orphan journal/weekly-log history. Two other tables DO
 * carry soft, non-FK references to a batch_students row (a curated HTE report
 * row, and User::isInfoSheetGated()'s legacy fallback) — see below.
 */
class BatchStudentPurgeService
{
    private const RETENTION_DAYS = 30;

    /**
     * @return array{purged: int, protected: int, cutoff: string}
     */
    public function purgeExpiredArchives(?Carbon $now = null): array
    {
        $cutoff = ($now ?? Carbon::now())->copy()->subDays(self::RETENTION_DAYS);

        $candidates = BatchStudent::whereNotNull('archived_at')
            ->where('archived_at', '<=', $cutoff)
            ->get(['id', 'student_id', 'status']);

        $purged = 0;
        $protected = 0;

        foreach ($candidates as $candidate) {
            if ($this->isSoleGateClearingRow($candidate)) {
                $protected++;

                continue;
            }

            $candidate->delete();
            $purged++;
        }

        return [
            'purged' => $purged,
            'protected' => $protected,
            'cutoff' => $cutoff->toDateTimeString(),
        ];
    }

    /**
     * A 'completed' row is the last thing keeping a legacy student (no
     * approved info sheet) past User::isInfoSheetGated()'s fallback check —
     * deleting it would silently re-gate an already-graduated student with
     * no info sheet to submit. Skip purging that one row; it's re-evaluated
     * every run, so it purges itself once the student is no longer relying
     * on it (an approved sheet appears, or another qualifying row exists).
     * A 'dropped' row never counted toward that check, so it's never
     * protected.
     */
    private function isSoleGateClearingRow(BatchStudent $candidate): bool
    {
        if ($candidate->status !== 'completed') {
            return false;
        }

        $student = User::find($candidate->student_id);

        if (! $student || $student->studentInformationSheets()->where('submission_status', 'approved')->exists()) {
            return false;
        }

        return ! BatchStudent::where('student_id', $candidate->student_id)
            ->whereIn('status', ['active', 'completed'])
            ->where('id', '!=', $candidate->id)
            ->exists();
    }
}
