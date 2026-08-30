<?php

namespace App\Http\Controllers\Concerns;

use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\SystemLog;
use App\Models\User;
use App\Models\WeeklyLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything about reviewing a weekly narrative journal that does NOT depend on
 * who is doing the reviewing: what "reviewable" means, the queue row shape, the
 * week payload, the per-intern notebook, the two verdict writes and the PDF.
 *
 * Two controllers review weekly journals — SupervisorJournalController for
 * supervisor-supported batches and Coordinator\CoordinatorJournalReviewController
 * for coordinator-centered ones — and they differ in EXACTLY one thing: which
 * enrollments they are allowed to touch. Everything else is here, for the same
 * reason WeeklyBundlingService has one private compileFor() behind both its
 * callers and EnrollmentService has one enrollOrReactivate() behind all three
 * of its own: a student's journal must not mean two different things depending
 * on which of the two people opened it.
 *
 * The concrete controllers therefore supply scope and nothing else. If a rule
 * about reviewing changes, it changes once.
 */
trait ReviewsWeeklyJournals
{
    /**
     * A verdict can only be given on a log that is still in play. An approved
     * log is finalized; a never-submitted draft was never handed in.
     *
     * @var list<string>
     */
    protected const REVIEWABLE_STATUSES = ['pending', 'returned'];

    protected function isReviewable(WeeklyLog $log): bool
    {
        return $log->submitted_at !== null && in_array($log->status, self::REVIEWABLE_STATUSES, true);
    }

    protected function assertReviewable(WeeklyLog $log): void
    {
        abort_if($log->submitted_at === null, 422, 'This weekly log has not been submitted yet.');
        abort_unless(
            in_array($log->status, self::REVIEWABLE_STATUSES, true),
            422,
            'This weekly log has already been finalized.'
        );
    }

    /**
     * Queue rows for a set of submitted logs, with each week's daily-entry
     * count resolved in a single extra query rather than one per row.
     *
     * @param  Collection<int, WeeklyLog>  $logs
     * @return Collection<int, array<string, mixed>>
     */
    protected function weeklyLogRows(Collection $logs): Collection
    {
        $entries = JournalEntry::whereIn('student_id', $logs->pluck('student_id')->unique())
            ->get(['student_id', 'entry_date']);

        return $logs->map(function (WeeklyLog $log) use ($entries) {
            $count = $entries
                ->where('student_id', $log->student_id)
                ->filter(fn (JournalEntry $entry) => $entry->entry_date->between($log->week_start, $log->week_end))
                ->count();

            return [
                'id' => $log->id,
                'student_id' => $log->student_id,
                'student_name' => $log->student?->name ?? '',
                'student_id_number' => $log->student?->student_id_number,
                'week_start' => $log->week_start->toDateString(),
                'week_end' => $log->week_end->toDateString(),
                'status' => $log->status,
                'submitted_at' => $log->submitted_at?->toIso8601String(),
                'entries_count' => $count,
            ];
        });
    }

    /**
     * One weekly log with its narrative and that week's daily entries. Callers
     * authorize BEFORE calling this — it does no scoping of its own.
     *
     * @return array<string, mixed>
     */
    protected function weeklyLogPayload(WeeklyLog $weeklyLog): array
    {
        $dailyEntries = JournalEntry::where('student_id', $weeklyLog->student_id)
            ->whereBetween('entry_date', [$weeklyLog->week_start->toDateString(), $weeklyLog->week_end->toDateString()])
            ->orderBy('entry_date')
            ->get(['entry_date', 'status', 'content']);

        $weeklyLog->load('student:id,name,student_id_number');

        return [
            'id' => $weeklyLog->id,
            'student' => [
                'id' => $weeklyLog->student_id,
                'name' => $weeklyLog->student?->name ?? '',
                'student_id_number' => $weeklyLog->student?->student_id_number,
            ],
            'week_start' => $weeklyLog->week_start->toDateString(),
            'week_end' => $weeklyLog->week_end->toDateString(),
            'status' => $weeklyLog->status,
            'supervisor_comment' => $weeklyLog->supervisor_comment,
            'narrative' => $weeklyLog->narrative ?? '',
            'submitted_at' => $weeklyLog->submitted_at?->toIso8601String(),
            'reviewed_at' => $weeklyLog->reviewed_at?->toIso8601String(),
            'reviewable' => $this->isReviewable($weeklyLog),
            'daily_entries' => $dailyEntries,
        ];
    }

    /**
     * Approve a submitted, still-in-play weekly log.
     *
     * `weekly_logs.supervisor_id` records WHO gave the verdict. It has always
     * been a nullable users FK, so a coordinator's id sits in it as naturally
     * as a supervisor's — on a coordinator-centered batch the column simply
     * means "the reviewer", which is what it has always recorded.
     */
    protected function approveWeeklyLog(WeeklyLog $weeklyLog, User $reviewer): WeeklyLog
    {
        $this->assertReviewable($weeklyLog);

        $weeklyLog->update([
            'status' => 'approved',
            'supervisor_id' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $weeklyLog->loadMissing('student:id,name');
        SystemLog::record('Weekly Journal Approved', "Approved {$weeklyLog->student?->name}'s week of {$weeklyLog->week_start->toDateString()}");

        return $weeklyLog->fresh();
    }

    /**
     * Return a submitted log to the student with a required explanatory
     * comment. This is what unlocks that week's daily entries for revision.
     */
    protected function returnWeeklyLog(WeeklyLog $weeklyLog, User $reviewer, string $comment): WeeklyLog
    {
        $this->assertReviewable($weeklyLog);

        $weeklyLog->update([
            'status' => 'returned',
            'supervisor_comment' => $comment,
            'supervisor_id' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $weeklyLog->loadMissing('student:id,name');
        SystemLog::record('Weekly Journal Returned', "Returned {$weeklyLog->student?->name}'s week of {$weeklyLog->week_start->toDateString()}");

        return $weeklyLog->fresh();
    }

    /**
     * The same document family as the student's own weekly-log PDF, so a
     * reviewer's downloaded copy looks like the document they reviewed.
     */
    protected function weeklyLogPdfResponse(WeeklyLog $weeklyLog, string $reviewerName): Response
    {
        $weeklyLog->load('student.program');

        $enrollment = BatchStudent::where('batch_id', $weeklyLog->batch_id)
            ->where('student_id', $weeklyLog->student_id)
            ->with('company:id,name')
            ->first();

        // Same "Week N" numbering as the student's own PDF: 1-based position
        // among that student's WeeklyLogs ordered by week_start ascending.
        $weekNumber = WeeklyLog::where('student_id', $weeklyLog->student_id)
            ->whereDate('week_start', '<', $weeklyLog->week_start->toDateString())
            ->count() + 1;

        $pdf = Pdf::loadView('pdf.weekly-log', [
            'narrative' => $weeklyLog->narrative ?? '',
            'weekNumber' => $weekNumber,
            'header' => [
                'student_name' => $weeklyLog->student?->name ?? '',
                'program' => $weeklyLog->student?->program?->name,
                'company_name' => $enrollment?->company?->name,
                'supervisor_name' => $reviewerName,
            ],
        ]);

        return $pdf->download("weekly-log-{$weeklyLog->id}.pdf");
    }

    /**
     * One intern's whole weekly-journal notebook — every week they have handed
     * in, oldest first, rather than the queue's one-status-at-a-time slice.
     * This is the "read the notebook end to end" surface.
     *
     * Never-submitted drafts are excluded: WeeklyBundlingService stamps a draft
     * every Monday for every active student, so including them would show the
     * reviewer work the intern has not handed in yet. `week_number` is counted
     * over ALL logs, drafts included, so it matches what pdf.weekly-log prints
     * — a gap in the visible list is therefore honest rather than a bug, and
     * `totals.drafts_hidden` says so in words.
     *
     * Callers authorize and resolve the enrollment; this builds the payload.
     *
     * @return array<string, mixed>
     */
    protected function notebookPayload(User $student, ?BatchStudent $enrollment): array
    {
        $allLogs = WeeklyLog::where('student_id', $student->id)
            ->orderBy('week_start')
            ->get();

        $weekNumbers = $allLogs->values()->mapWithKeys(
            fn (WeeklyLog $log, int $index) => [$log->id => $index + 1]
        );

        $submitted = $allLogs->filter(fn (WeeklyLog $log) => $log->submitted_at !== null)->values();

        // Daily-entry counts for every listed week, in one query.
        $entries = JournalEntry::where('student_id', $student->id)->get(['entry_date']);

        $weeks = $submitted->map(fn (WeeklyLog $log) => [
            'id' => $log->id,
            'week_number' => $weekNumbers[$log->id],
            'week_start' => $log->week_start->toDateString(),
            'week_end' => $log->week_end->toDateString(),
            'status' => $log->status,
            'submitted_at' => $log->submitted_at?->toIso8601String(),
            'reviewed_at' => $log->reviewed_at?->toIso8601String(),
            'reviewable' => $this->isReviewable($log),
            'has_comment' => filled($log->supervisor_comment),
            'entries_count' => $entries
                ->filter(fn (JournalEntry $entry) => $entry->entry_date->between($log->week_start, $log->week_end))
                ->count(),
        ]);

        $student->loadMissing('program:id,code,name');

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'student_id_number' => $student->student_id_number,
                'avatar_url' => $student->avatar_url,
                'program' => $student->program?->code ?? $student->program?->name ?? '',
                'company' => $enrollment?->company?->name ?? '',
                'batch' => $enrollment?->batch?->name ?? '',
                'enrollment_status' => $enrollment?->status,
            ],
            'totals' => [
                'total' => $weeks->count(),
                'pending' => $weeks->where('status', 'pending')->count(),
                'approved' => $weeks->where('status', 'approved')->count(),
                'returned' => $weeks->where('status', 'returned')->count(),
                'drafts_hidden' => $allLogs->count() - $submitted->count(),
            ],
            'weeks' => $weeks->values(),
        ];
    }
}
