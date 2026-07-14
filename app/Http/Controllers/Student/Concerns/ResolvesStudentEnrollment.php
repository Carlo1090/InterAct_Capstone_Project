<?php

namespace App\Http\Controllers\Student\Concerns;

use App\Models\BatchStudent;
use Carbon\Carbon;

trait ResolvesStudentEnrollment
{
    /**
     * The enrollment that permits WRITES (journal/weekly-log saves and
     * submits) — active only. A completed or dropped student can no longer
     * write.
     */
    protected function activeEnrollment(int $studentId): ?BatchStudent
    {
        return BatchStudent::with(['batch.coordinator', 'batch.journalTemplate', 'company', 'supervisor'])
            ->where('student_id', $studentId)
            ->where('status', 'active')
            ->latest('enrolled_at')
            ->first();
    }

    /**
     * The enrollment that permits READS (viewing journals, weekly logs,
     * calendar, PDFs) — the latest active OR completed enrollment, so a
     * student whose OJT was marked completed keeps access to everything
     * they wrote.
     */
    protected function currentEnrollment(int $studentId): ?BatchStudent
    {
        return BatchStudent::with(['batch.coordinator', 'batch.journalTemplate', 'company', 'supervisor'])
            ->where('student_id', $studentId)
            ->whereIn('status', ['active', 'completed'])
            ->latest('enrolled_at')
            ->first();
    }

    /**
     * The REAL-TIME OJT window: starts at the batch start date and runs
     * until the coordinator marks the enrollment completed (completed_at),
     * or today while it is still open. The info sheet's ojt_start_date /
     * ojt_end_date are informational estimates and deliberately play no
     * part here.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    protected function ojtRange(BatchStudent $enrollment): array
    {
        return [
            'start' => $enrollment->batch->start_date->copy()->startOfDay(),
            'end' => $enrollment->completed_at
                ? $enrollment->completed_at->copy()->startOfDay()
                : today(),
        ];
    }
}
