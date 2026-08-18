<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\HteReport;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Additive demo seed for Coordinator > HTE & Student Interns List.
 *
 * This deliberately does not truncate, delete, migrate, or reset anything. It
 * only backfills the CABM-B demo batches' academic_year when older local data
 * has it blank, then upserts Balbero's combined HTE report for that year.
 */
class HteStudentInternsListDemoSeeder extends Seeder
{
    private const ACADEMIC_YEAR = '2026';

    private const BATCH_NAMES = [
        'BSA 2026 Internship',
        'BSBA-FM 2026 Internship',
        'BSBA-MM 2026 Internship',
        'BSBA-OM 2026 Internship',
    ];

    private const SIGNATORIES = [
        'signatory_prepared_name' => 'MARIA ANTONNETTE B. GULILAT, MA, LPT',
        'signatory_prepared_title' => 'Practicum Coordinator, CABM-B',
        'signatory_certified_name' => 'MA. ANGELICA B. CALUNSAG, MSA, CPA',
        'signatory_certified_title' => 'CABM Dean',
    ];

    public function run(): void
    {
        $coordinator = User::where('username', 'mdcbalbero')->first();

        if (! $coordinator) {
            return;
        }

        $this->backfillDemoBatchAcademicYears($coordinator);

        $rows = $this->reportRows($coordinator);

        if ($rows === []) {
            return;
        }

        $report = HteReport::firstOrNew([
            'coordinator_id' => $coordinator->id,
            'program_id' => null,
            'academic_year' => self::ACADEMIC_YEAR,
        ]);

        $report->status ??= 'draft';
        $report->report_data = $this->mergeReportData($report->report_data ?? [], $rows);
        $report->save();
    }

    private function backfillDemoBatchAcademicYears(User $coordinator): void
    {
        Batch::where('coordinator_id', $coordinator->id)
            ->whereIn('name', self::BATCH_NAMES)
            ->whereNull('academic_year')
            ->update(['academic_year' => self::ACADEMIC_YEAR]);
    }

    /**
     * Preserve prior curation: existing candidate overrides, manual rows,
     * deletions, signatories, and status survive a re-seed. New demo
     * enrollments are appended only when their batch_student id is missing.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<int, array<string, mixed>>  $seedRows
     * @return array<string, mixed>
     */
    private function mergeReportData(array $existing, array $seedRows): array
    {
        $rows = collect($existing['rows'] ?? [])
            ->keyBy(fn ($row) => (int) ($row['id'] ?? 0));

        foreach ($seedRows as $row) {
            $id = (int) $row['id'];

            if (! $rows->has($id)) {
                $rows->put($id, $row);
            }
        }

        return [
            'rows' => $rows->values()->all(),
            'manual_rows' => $existing['manual_rows'] ?? [],
            'deleted_ids' => $existing['deleted_ids'] ?? [],
            'signatory_prepared_name' => $existing['signatory_prepared_name'] ?? self::SIGNATORIES['signatory_prepared_name'],
            'signatory_prepared_title' => $existing['signatory_prepared_title'] ?? self::SIGNATORIES['signatory_prepared_title'],
            'signatory_certified_name' => $existing['signatory_certified_name'] ?? self::SIGNATORIES['signatory_certified_name'],
            'signatory_certified_title' => $existing['signatory_certified_title'] ?? self::SIGNATORIES['signatory_certified_title'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reportRows(User $coordinator): array
    {
        $programIds = $coordinator->coordinatorProgramIds();

        return BatchStudent::whereHas('batch', fn ($query) => $query
            ->whereIn('program_id', $programIds)
            ->where('academic_year', self::ACADEMIC_YEAR)
        )
            ->with(['student.studentProfile', 'company', 'batch.program'])
            ->get()
            ->sortBy(fn (BatchStudent $enrollment) => [
                $enrollment->company?->name ?? '',
                $enrollment->student?->name ?? '',
            ])
            ->map(fn (BatchStudent $enrollment) => [
                'id' => (int) $enrollment->id,
                'host_establishment' => (string) ($enrollment->company?->name ?? ''),
                'student_name' => $this->studentName($enrollment),
                'program' => $this->program($enrollment),
                'gender' => $this->gender($enrollment),
                'duration' => $this->duration($enrollment),
                'included' => true,
            ])
            ->values()
            ->all();
    }

    private function studentName(BatchStudent $enrollment): string
    {
        $student = $enrollment->student;
        $name = trim((string) ($student?->name ?? ''));

        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        if (count($parts) === 2) {
            $middle = trim((string) ($student?->studentProfile?->middle_name ?? ''));

            return $this->formatLastFirst($parts[1], $parts[0], $middle);
        }

        return $name;
    }

    private function formatLastFirst(string $last, string $first, string $middle): string
    {
        $initial = $middle !== '' ? ' '.mb_strtoupper(mb_substr($middle, 0, 1)).'.' : '';

        return "{$last}, {$first}{$initial}";
    }

    private function program(BatchStudent $enrollment): string
    {
        $code = (string) ($enrollment->batch?->program?->code ?? '');
        $yearLevel = (string) ($enrollment->student?->studentProfile?->year_level ?? '');

        if ($code !== '' && preg_match('/\d/', $yearLevel, $matches)) {
            return "{$code}-{$matches[0]}";
        }

        return $code;
    }

    private function gender(BatchStudent $enrollment): string
    {
        $sex = (string) ($enrollment->student?->studentProfile?->sex ?? '');

        return $sex === '' ? '' : ucfirst($sex);
    }

    private function duration(BatchStudent $enrollment): string
    {
        $batch = $enrollment->batch;

        if (! $batch?->start_date || ! $batch?->end_date) {
            return '';
        }

        return $batch->start_date->format('F j, Y').' - '.$batch->end_date->format('F j, Y');
    }
}
