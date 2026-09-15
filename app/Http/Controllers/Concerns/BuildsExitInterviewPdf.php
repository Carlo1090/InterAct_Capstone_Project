<?php

namespace App\Http\Controllers\Concerns;

use App\Models\BatchStudent;
use App\Models\StudentExitInterview;
use App\Services\DtrService;
use App\Support\ExitInterview\ExitInterviewForm;
use App\Support\ExitInterview\ExitInterviewForms;
use App\Support\ExitInterviewFormLayout as Layout;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared renderer for the exit interview form — whichever department's form
 * the interview was answered under (StudentExitInterview::form()) — so the
 * student's own download and the coordinator's in-scope download produce the
 * identical measured facsimile — the same call already made for
 * the individual Student Information Sheet (BuildsInfoSheetPdf) and the Weekly
 * Activity Log (BuildsWeeklyActivityLogPdf).
 *
 * Reference: docs/reference/INTERNSHIP PROGRAM STUDENT EXIT INTERVIEW - BUSINESS.pdf
 */
trait BuildsExitInterviewPdf
{
    /**
     * The reference form is the CABM edition and prints these three names
     * pre-filled. They are used ONLY as a fallback: the batch's real
     * coordinator and the department's real dean print in their place when
     * the record has them, because printing the reference's names onto
     * another department's student would be plainly wrong. The same call
     * already made for the Weekly Activity Log's department line.
     *
     * And ONLY on the CABM form itself (ExitInterviewForms::DEFAULT): another
     * department's form with no dean on record prints a blank to be signed,
     * never the business department's dean.
     */
    private const REFERENCE_COORDINATOR = 'Maria Antonnette B. Gulilat, MABM, LPT';

    private const REFERENCE_COORDINATOR_UPPER = 'MARIA ANTONNETTE B. GULILAT, MABM, LPT';

    private const REFERENCE_DEAN = 'MA. ANGELICA B. CALUNSAG, MSA, CPA';

    protected function renderExitInterviewPdf(StudentExitInterview $interview, ?string $filename = null): Response
    {
        $responses = $interview->responses ?? [];
        $coordinatorSection = $interview->coordinator_section ?? [];
        $layout = Layout::for($interview->form());

        // The coordinator's own free text rides the same wrapper as the
        // student's, so every field on the form is broken onto its printed
        // rules by one piece of code.
        $answers = $responses;
        $answers['pending_detail'] = $coordinatorSection['pending_detail'] ?? null;
        $answers['remarks'] = $coordinatorSection['remarks'] ?? null;

        $pdf = Pdf::loadView('pdf.exit-interview', [
            'layout' => $layout,
            'form' => $this->exitInterviewHeader($interview),
            'ticked' => $this->exitInterviewTicked($interview->form(), $responses, $coordinatorSection['compliance'] ?? null),
            'lines' => $layout->place($answers),
        ])
            // The reference is 612 x 936pt — the Philippine "long bond"
            // (8.5" x 13"), NOT Letter and emphatically not dompdf's A4
            // default. Every measured x and y below assumes this box.
            ->setPaper([0, 0, Layout::PAGE_WIDTH, Layout::PAGE_HEIGHT], 'portrait');

        $slug = str($interview->student?->name ?? (string) $interview->student_id)->slug();

        return $pdf->download($filename ?? "exit-interview-{$slug}.pdf");
    }

    /**
     * Section A, resolved from the interview's OWN (student_id, batch_id) pair
     * rather than from "whatever the student is enrolled in right now" — the
     * exit interview is filed at the end of a placement and read long after
     * it, so it must keep printing that placement's company and coordinator.
     * The same reasoning as BuildsWeeklyActivityLogPdf's header.
     *
     * @return array<string, string>
     */
    protected function exitInterviewHeader(StudentExitInterview $interview): array
    {
        $interview->loadMissing(['student.program.department', 'student.studentProfile', 'reviewer:id,name']);

        $student = $interview->student;
        $info = $interview->student_info ?? [];

        $enrollment = BatchStudent::where('student_id', $interview->student_id)
            ->where('batch_id', $interview->batch_id)
            ->with(['batch.coordinator:id,name', 'company:id,name'])
            ->first();

        $coordinator = $enrollment?->batch?->coordinator?->name;
        $dean = $student?->program?->department?->dean_name;
        $isReferenceForm = $interview->form()->key === ExitInterviewForms::DEFAULT;

        $fields = [
            'student_name' => $student?->name ?? '',
            'program' => $student?->program?->code ?? $student?->program?->name ?? '',
            'company' => $enrollment?->company?->name ?? '',
            'department_position' => (string) ($info['department_position'] ?? ''),
            'training_period' => $this->exitInterviewTrainingPeriod($enrollment),
            'total_hours' => $this->exitInterviewTotalHours($interview, $enrollment),
            'date_of_interview' => $this->formatFormDate($info['date_of_interview'] ?? null),
            'coordinator_name' => $coordinator ?: ($isReferenceForm ? self::REFERENCE_COORDINATOR : ''),
            'coordinator_signature_name' => mb_strtoupper($coordinator ?: '') ?: ($isReferenceForm ? self::REFERENCE_COORDINATOR_UPPER : ''),
            'coordinator_reviewed_on' => $this->formatFormDate($interview->reviewed_at?->toDateString()),
            'dean_name' => mb_strtoupper($dean ?: '') ?: ($isReferenceForm ? self::REFERENCE_DEAN : ''),
        ];

        // Each value is trimmed to its OWN printed blank by the blade, from
        // the width the layout computed for it — nothing is hardcoded here,
        // so moving a column cannot leave a stale clamp behind.
        return $fields;
    }

    /**
     * Every printed box that should carry a check mark, as the mark keys the
     * layout itself hands out: `q2_choice:yes` for a ☐ Yes ☐ No pair,
     * `q33:good` for a rating row, `compliance:pending` for the coordinator's
     * block. Only a value the form actually offers is ticked, so a stale or
     * tampered answer prints an empty box rather than a mark on nothing.
     *
     * @param  array<string, mixed>  $responses
     * @return array<string, true>
     */
    protected function exitInterviewTicked(ExitInterviewForm $form, array $responses, mixed $compliance): array
    {
        $ticked = [];

        foreach ($form->questions() as $question) {
            if ($question['type'] === ExitInterviewForm::TYPE_YES_NO_TEXT) {
                $value = $responses[$question['choice']] ?? null;

                if (in_array($value, ['yes', 'no'], true)) {
                    $ticked[$question['choice'].':'.$value] = true;
                }
            }

            if ($question['type'] === ExitInterviewForm::TYPE_SCALE) {
                $value = $responses[$question['key']] ?? null;

                if (is_string($value) && isset($question['options'][$value])) {
                    $ticked[$question['key'].':'.$value] = true;
                }
            }
        }

        if ($this->normalizeCompliance($compliance) !== null) {
            $ticked['compliance:'.$compliance] = true;
        }

        return $ticked;
    }

    /**
     * "Training Period" on the paper form is the real OJT window, matching
     * ResolvesStudentEnrollment::ojtRange(): the batch start through the
     * coordinator's completion stamp, or today while the placement is open.
     */
    private function exitInterviewTrainingPeriod(?BatchStudent $enrollment): string
    {
        $start = $enrollment?->batch?->start_date;

        if (! $start) {
            return '';
        }

        $end = $enrollment->completed_at ?? today();

        // The printed blank for "Training Period" is only 103pt wide, so the
        // year is written once when both ends share it — "Jun 29 - Aug 29,
        // 2026" sets 93pt where the fully spelled range sets 120pt and gets
        // clipped mid-date. Same treatment as the Weekly Activity Log's
        // inclusive dates, and for the same reason.
        if ($start->year === $end->year) {
            return $start->format('M j').' - '.$end->format('M j, Y');
        }

        return $start->format('M j, Y').' - '.$end->format('M j, Y');
    }

    /**
     * "Total Hours Completed" prefers what the student typed and falls back to
     * the Daily Time Record where their coordinator runs it — the same rule as
     * the Weekly Activity Log's "No. of hours": a typed value is NEVER
     * overwritten, because this is a paper facsimile somebody signs by hand
     * and a forgotten clock-out must stay correctable before printing. Hours
     * are never fabricated: with DTR off and nothing typed, the blank prints
     * blank.
     */
    private function exitInterviewTotalHours(StudentExitInterview $interview, ?BatchStudent $enrollment): string
    {
        $typed = trim((string) (($interview->student_info ?? [])['total_hours'] ?? ''));

        if ($typed !== '') {
            return $typed;
        }

        // One rule for "does the DTR run here", shared with DtrService so the
        // prefill cannot disagree with the feature it prefills from.
        if (! app(DtrService::class)->runsForEnrollment($enrollment)) {
            return '';
        }

        $minutes = app(DtrService::class)->minutesCompleted($interview->student_id, $interview->batch_id);

        return $minutes > 0 ? (string) round($minutes / 60, 1) : '';
    }

    private function normalizeCompliance(mixed $value): ?string
    {
        return in_array($value, ['complete', 'pending'], true) ? $value : null;
    }

    private function formatFormDate(mixed $value): string
    {
        if (! $value) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('M j, Y');
        } catch (\Throwable) {
            return '';
        }
    }
}
