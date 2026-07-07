<?php

namespace App\Http\Controllers\Student\Concerns;

use App\Models\BatchStudent;
use App\Models\StudentInformationSheet;
use Carbon\Carbon;

trait ResolvesStudentEnrollment
{
    protected function activeEnrollment(int $studentId): ?BatchStudent
    {
        return BatchStudent::with(['batch.coordinator', 'batch.journalTemplate', 'company', 'supervisor'])
            ->where('student_id', $studentId)
            ->where('status', 'active')
            ->latest('enrolled_at')
            ->first();
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    protected function ojtRange(BatchStudent $enrollment): array
    {
        $sheet = StudentInformationSheet::where('student_id', $enrollment->student_id)
            ->where('batch_id', $enrollment->batch_id)
            ->first();

        $start = $sheet?->ojt_info['ojt_start_date'] ?? null;
        $end = $sheet?->ojt_info['ojt_end_date'] ?? null;

        return [
            'start' => $start ? Carbon::parse($start)->startOfDay() : $enrollment->batch->start_date->copy()->startOfDay(),
            'end' => $end ? Carbon::parse($end)->startOfDay() : $enrollment->batch->end_date->copy()->startOfDay(),
        ];
    }
}
