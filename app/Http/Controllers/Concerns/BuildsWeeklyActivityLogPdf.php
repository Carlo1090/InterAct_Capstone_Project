<?php

namespace App\Http\Controllers\Concerns;

use App\Models\BatchStudent;
use App\Models\WeeklyActivityEntry;
use App\Models\WeeklyActivityLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared renderer for the MDC Weekly Activity Log and Time Log Summary, so the
 * student's own download and the coordinator's in-scope download produce the
 * identical measured facsimile — the same call already made for the individual
 * Student Information Sheet (BuildsInfoSheetPdf).
 *
 * The header block is resolved from the LOG's own (student_id, batch_id) pair
 * rather than from "whatever the student is enrolled in right now", so a sheet
 * still prints its real company and coordinator after the enrollment is marked
 * completed — which is exactly when a coordinator is most likely to be
 * collecting these for the SIPP file.
 */
trait BuildsWeeklyActivityLogPdf
{
    /**
     * The paper form is the CABM/Business Department edition, and these two
     * lines are printed on it verbatim. Deliberately literal rather than
     * derived from `departments.name`, which is seeded to the short code
     * ("CABM-B") and would print wrongly — the same call already made for the
     * GROUP Student Information Sheet.
     */
    private const DEFAULT_DEPARTMENT_LINE = 'College of Accountancy, Business and Management';

    private const DEFAULT_UNIT_LINE = 'Business Department';

    /**
     * The blank form carries pre-printed rows. Pad to this many so a sparse log
     * still prints like the paper form; the table itself GROWS past it.
     */
    private const MIN_FORM_ROWS = 5;

    /**
     * The reference form is set in Calibri Bold 11pt throughout. Carlito is
     * metric-compatible with Calibri and is SIL OFL licensed, so unlike Calibri
     * itself it can ship in the repo and in the Docker image. dompdf bundles
     * nothing resembling it, so the faces are registered by hand here rather
     * than through @font-face — a CSS url() to a local .ttf goes through
     * dompdf's URL resolver, which does not survive a Windows drive-letter path.
     *
     * Registration is best-effort on purpose: the blade's font stack falls back
     * to Helvetica, so a missing or unreadable font file degrades the type
     * rather than failing the download.
     */
    private const FORM_FONT_FAMILY = 'carlito';

    private const FORM_FONT_FILES = [
        'normal' => 'resources/fonts/Carlito-Regular.ttf',
        'bold' => 'resources/fonts/Carlito-Bold.ttf',
    ];

    protected function renderWeeklyActivityLogPdf(WeeklyActivityLog $log, ?string $filename = null): Response
    {
        $log->load(['entries' => fn ($query) => $query->orderBy('sort_order')]);

        $pdf = Pdf::loadView('pdf.weekly-activity-log', [
            'log' => $log,
            'header' => $this->weeklyActivityLogHeader($log),
            'periodCovered' => $this->formatRange($log->week_start, $log->week_end),
            'hours' => $this->formatHours($log->no_of_hours),
            'rows' => $this->formRows($log),
            // dompdf defaults to A4, which silently narrows every measured column.
        ])->setPaper('letter', 'portrait');

        $this->registerWeeklyActivityLogFonts($pdf);

        $slug = str($log->week_start?->toDateString() ?? (string) $log->id)->slug();

        return $pdf->download($filename ?? "weekly-activity-log-{$slug}.pdf");
    }

    /**
     * Make the two Carlito faces available to the blade's `carlito` family.
     * Registration is cached into dompdf's font dir on first use.
     */
    protected function registerWeeklyActivityLogFonts(mixed $pdf): void
    {
        $dompdf = $pdf->getDomPDF();

        // laravel-dompdf's shipped config turns subsetting OFF, which is
        // harmless while every PDF uses a base-14 font but embeds the whole
        // 682KB face the moment one does not. Switched on for THIS document
        // only (the option lives on the instance, not the container binding);
        // it takes the blank form from ~600KB to ~12KB.
        $dompdf->getOptions()->setIsFontSubsettingEnabled(true);

        $metrics = $dompdf->getFontMetrics();

        foreach (self::FORM_FONT_FILES as $weight => $relativePath) {
            $path = base_path($relativePath);

            if (! is_file($path)) {
                continue;
            }

            $metrics->registerFont(
                ['family' => self::FORM_FONT_FAMILY, 'style' => 'normal', 'weight' => $weight],
                $path
            );
        }
    }

    /**
     * The read-only masthead: who wrote the sheet, under whom, where.
     */
    protected function weeklyActivityLogHeader(WeeklyActivityLog $log): array
    {
        $log->loadMissing(['student.program', 'student.studentProfile']);

        $student = $log->student;

        $enrollment = BatchStudent::where('student_id', $log->student_id)
            ->where('batch_id', $log->batch_id)
            ->with(['batch.coordinator:id,name', 'company:id,name', 'supervisor:id,name'])
            ->first();

        $program = $student?->program?->code ?? $student?->program?->name;
        $year = $student?->studentProfile?->year_level;

        return [
            'student_name' => $student?->name,
            'program' => $student?->program?->name,
            'year_level' => $year,
            'coordinator_name' => $enrollment?->batch?->coordinator?->name,
            'company_name' => $enrollment?->company?->name,
            'supervisor_name' => $enrollment?->supervisor?->name,
            'area_assigned' => $enrollment?->assigned_division,

            // Composed for the paper form's own field labels.
            'department_line' => self::DEFAULT_DEPARTMENT_LINE,
            'unit_line' => self::DEFAULT_UNIT_LINE,
            'program_and_year' => trim(collect([$program, $year])->filter()->implode(' ')),
            // The form says "Faculty Adviser"; this system has no adviser role,
            // so the batch coordinator is the person that field means.
            'faculty_adviser' => $enrollment?->batch?->coordinator?->name,
        ];
    }

    /**
     * One row per entry, padded out to the blank form's pre-printed row count.
     */
    private function formRows(WeeklyActivityLog $log): array
    {
        $rows = $log->entries->map(fn (WeeklyActivityEntry $entry) => [
            'dates' => $this->formatRange($entry->inclusive_date_start, $entry->inclusive_date_end),
            'activities' => $entry->activities,
            'documents_records' => $entry->documents_records,
            'objectives' => $entry->objectives,
            'supervisor_name' => $entry->supervisor_name,
            'supervisor_position' => $entry->supervisor_position,
        ])->all();

        $blank = [
            'dates' => '', 'activities' => '', 'documents_records' => '',
            'objectives' => '', 'supervisor_name' => '', 'supervisor_position' => '',
        ];

        while (count($rows) < self::MIN_FORM_ROWS) {
            $rows[] = $blank;
        }

        return $rows;
    }

    private function formatRange(mixed $start, mixed $end): string
    {
        if (! $start && ! $end) {
            return '';
        }

        if (! $start || ! $end) {
            return ($start ?? $end)->format('M j, Y');
        }

        // Same year and month reads as "Jan 1 - 5, 2026"; otherwise spell both out.
        if ($start->isSameMonth($end)) {
            return $start->format('M j').' - '.$end->format('j, Y');
        }

        return $start->format('M j').' - '.$end->format('M j, Y');
    }

    private function formatHours(mixed $hours): string
    {
        if ($hours === null || $hours === '') {
            return '';
        }

        // decimal(5,1) comes back as "40.0" — drop a pointless trailing zero.
        return rtrim(rtrim(number_format((float) $hours, 1, '.', ''), '0'), '.');
    }
}
