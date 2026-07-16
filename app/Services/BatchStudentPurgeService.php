<?php

namespace App\Services;

use App\Models\BatchStudent;
use Carbon\Carbon;

/**
 * Permanently deletes any batch_students row archived (BatchRosterController::archive())
 * 30+ days ago. Shared by the nightly schedule
 * (App\Console\Commands\PurgeArchivedBatchStudents) and the admin demo-trigger
 * endpoint so the purge logic lives in exactly one place.
 *
 * No other table has a foreign key referencing batch_students.id (journal
 * entries and weekly logs key off student_id + batch_id directly), so this
 * hard delete cannot orphan journal/weekly-log history.
 */
class BatchStudentPurgeService
{
    private const RETENTION_DAYS = 30;

    /**
     * @return array{purged: int, cutoff: string}
     */
    public function purgeExpiredArchives(?Carbon $now = null): array
    {
        $cutoff = ($now ?? Carbon::now())->copy()->subDays(self::RETENTION_DAYS);

        $purged = BatchStudent::whereNotNull('archived_at')
            ->where('archived_at', '<=', $cutoff)
            ->delete();

        return [
            'purged' => $purged,
            'cutoff' => $cutoff->toDateTimeString(),
        ];
    }
}
