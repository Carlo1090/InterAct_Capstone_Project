<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\WeeklyLog;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Six weeks of real journal traffic for mdcbalsup's three interns, so every
 * supervisor surface has something in it.
 *
 * Before this, mdcbalsup's world had a roster and nothing else: the Journals
 * queue was empty on all three tabs, the per-intern notebook said "has not
 * submitted any weekly journals yet", and the dashboard's four stat cards all
 * read 0. The only demo supervisor with journals was mdcsupervisor, who has
 * exactly ONE pending week (SupervisorReviewDemoSeeder) — enough to prove the
 * review endpoint works, not enough to show what the surfaces look like in
 * use.
 *
 * What this produces, deliberately spread rather than uniform:
 *
 *   Jomar Bactol    3 approved, 1 returned, 1 pending, 1 never submitted
 *   Rhea Lumapas    4 approved, 2 pending
 *   Kenneth Auza    3 approved, 1 returned, 1 pending, 1 never submitted
 *
 * — so the queue's Pending / Approved / Returned tabs are all non-empty, the
 * notebook shows a mix down one intern, the supervisor's "recently reviewed"
 * panel has history, and TWO interns carry an unsubmitted week so the
 * notebook's "still being drafted" line (and the gap in its Week numbering)
 * is visible on real data rather than only in a test.
 *
 * THREE things here are load-bearing, not decoration:
 *
 * 1. **The narrative is compiled in WeeklyBundlingService's exact shape** —
 *    "MONDAY\n<text>\n\nTUESDAY\n<text>". The pre-existing demo narrative is
 *    one flat paragraph, so the day-header parsing that
 *    `WeeklyJournalPaperView` and `pdf.weekly-log` both do never actually
 *    showed on demo data. A seeded log that does not look like a bundled one
 *    hides the feature it is meant to demonstrate.
 * 2. **Every daily entry carries `daily_accomplishment`**, which is the one
 *    key bundling compiles from, plus the SIPP trio
 *    (`issues_concerns`/`solutions`/`recommendations`) so the coordinator's
 *    Annual SIPP report has rows from these students too.
 * 3. **Weeks are anchored to the most recently COMPLETED week and walked
 *    backwards**, never to fixed dates, and any week landing before the
 *    batch's `start_date` is skipped. The queue sorts by `submitted_at`
 *    descending, so data anchored to `now()` is also what keeps these rows at
 *    the top of it whenever the database is seeded.
 *
 * Re-runnable: the weekly log is located by (student, batch, week_start) with
 * **whereDate()**, never plain equality — `week_start` is a `date`-cast column
 * and SQLite keeps a time component on it, so equality misses the previous
 * run's row and inserts a duplicate.
 */
class CabmbSupervisorJournalDemoSeeder extends Seeder
{
    private const WEEKS = 6;

    /**
     * Per-intern week plan, oldest week first. 'draft' means compiled but
     * never handed in — the state WeeklyBundlingService leaves behind every
     * Monday, and the one the notebook deliberately hides.
     *
     * @var array<string, array<int, string>>
     */
    private const PLAN = [
        'mdcbalintern1' => ['approved', 'approved', 'approved', 'returned', 'pending', 'draft'],
        'mdcbalintern2' => ['approved', 'approved', 'approved', 'approved', 'pending', 'pending'],
        'mdcbalintern3' => ['approved', 'returned', 'approved', 'approved', 'pending', 'draft'],
    ];

    /**
     * Branch-banking work, one line per working day. Rotated by a per-student
     * offset so three interns at the same bank do not file byte-identical
     * journals — the same reasoning as CabmbWeeklyTimeLogDemoSeeder.
     *
     * @var array<int, string>
     */
    private const ACCOMPLISHMENTS = [
        'Observed the teller line during the morning rush and logged how each transaction type was handled.',
        'Encoded new member application forms into the branch system and checked each one against its ID.',
        'Helped sort and file the previous day\'s deposit slips by account number.',
        'Sat in on the branch briefing and took notes on the week\'s savings campaign targets.',
        'Verified signature cards against the member masterlist and flagged three that needed updating.',
        'Prepared the daily cash position sheet under the cashier\'s supervision.',
        'Assisted in releasing passbooks and explained the updating procedure to walk-in members.',
        'Reconciled the petty cash fund and prepared the replenishment voucher.',
        'Encoded loan application details and attached the supporting income documents.',
        'Reviewed the aging report for past-due loan accounts and listed accounts for follow-up.',
        'Called members with maturing time deposits to remind them of their renewal dates.',
        'Filed the month\'s official receipts in sequence and noted two missing numbers for the cashier.',
        'Shadowed the loan officer during a credit interview and observed the questions asked.',
        'Updated the member ledger cards for accounts with over-the-counter deposits.',
        'Helped prepare the branch\'s weekly deposit summary for the operations supervisor.',
        'Assisted in the count and packing of coins for the vault.',
        'Encoded collection remittances from the field collectors and balanced them against the receipts.',
        'Prepared statement of account printouts requested by members.',
        'Organised the loan folders for the upcoming internal audit.',
        'Observed the end-of-day balancing of the teller drawers.',
        'Drafted the notice letters for accounts that stayed dormant past twelve months.',
        'Encoded the new share capital subscriptions into the members\' equity record.',
        'Assisted the accounting clerk in checking journal vouchers against source documents.',
        'Helped orient two new members on the savings products and the passbook system.',
        'Sorted returned mail and updated the addresses on the member database.',
        'Prepared the summary of withdrawals for the branch manager\'s morning review.',
        'Encoded the day\'s over-the-counter loan payments and printed the acknowledgement receipts.',
        'Assisted in preparing the branch\'s report on the savings mobilisation drive.',
        'Reviewed the checklist of documentary requirements for new loan applicants.',
        'Helped file the approved loan disbursement vouchers for the week.',
        'Observed how the branch handles a stop-payment request from start to finish.',
        'Encoded the results of the member satisfaction survey forms collected at the counter.',
    ];

    /** @var array<int, string> */
    private const ISSUES = [
        'The queue at the counter built up quickly after lunch and slowed the encoding work.',
        'A few application forms were missing the required signature, so they could not be processed.',
        'The branch system was slow in the afternoon, which delayed the encoding backlog.',
        'Some member records had outdated contact numbers, making follow-up calls impossible.',
        'The filing area was disorganised, so locating older folders took longer than expected.',
    ];

    /** @var array<int, string> */
    private const SOLUTIONS = [
        'Asked the supervisor to prioritise the queue and continued encoding once it cleared.',
        'Set the incomplete forms aside and listed the members to be contacted for signing.',
        'Switched to the manual logsheet and encoded the entries once the system recovered.',
        'Noted the outdated records and forwarded the list to the membership clerk for updating.',
        'Re-sorted the folders by account number while waiting for the next batch of work.',
    ];

    /** @var array<int, string> */
    private const RECOMMENDATIONS = [
        'A second encoder during the after-lunch peak would keep the backlog from building.',
        'A completeness check at the counter before accepting a form would save a return trip.',
        'A short offline logsheet template would keep work moving when the system is slow.',
        'Contact details could be confirmed at every counter visit to keep the database current.',
        'Folders could be re-sorted quarterly so audit season does not start with a search.',
    ];

    /**
     * Supervisor comments for the returned weeks. Real feedback, so the
     * student's "returned" banner and the notebook's comment callout show
     * something a supervisor would plausibly have written.
     *
     * @var array<int, string>
     */
    private const RETURN_COMMENTS = [
        'Please expand Wednesday and Thursday — write what you actually did with the ledger cards, not just that you updated them. Also add the issue you raised with me about the missing receipt numbers.',
        'Good detail on the loan folders, but Tuesday and Friday are too short to show a full day of work. Please add the tasks you handled at the counter before resubmitting.',
    ];

    public function run(): void
    {
        $supervisor = User::where('username', 'mdcbalsup')->first();

        if (! $supervisor) {
            return;
        }

        // A week is only complete once its Sunday has passed — the same rule
        // WeeklyBundlingService::mostRecentlyCompletedWeekStart() applies.
        $latestWeek = Carbon::today()->startOfWeek(Carbon::MONDAY)->subWeek();

        $studentIndex = 0;

        foreach (self::PLAN as $username => $statuses) {
            $student = User::where('username', $username)->first();

            if (! $student) {
                continue;
            }

            $enrollment = BatchStudent::where('student_id', $student->id)
                ->where('supervisor_id', $supervisor->id)
                ->latest('id')
                ->first();

            if (! $enrollment) {
                continue;
            }

            $batch = Batch::find($enrollment->batch_id);
            $batchStart = $batch?->start_date?->copy()->startOfDay();

            $returnedSoFar = 0;

            foreach ($statuses as $index => $status) {
                // $index 0 is the OLDEST of the six weeks.
                $weekStart = $latestWeek->copy()->subWeeks(self::WEEKS - 1 - $index);
                $weekEnd = $weekStart->copy()->addDays(6);

                // Never seed a week the student had not started yet — a
                // journal dated before the batch begins is visibly wrong on
                // every surface that shows the OJT range.
                if ($batchStart && $weekEnd->lt($batchStart)) {
                    continue;
                }

                $entries = $this->seedWeekEntries($student, $enrollment->batch_id, $weekStart, $studentIndex, $index);
                $narrative = $this->compileNarrative($weekStart, $entries);

                $this->seedWeeklyLog(
                    student: $student,
                    batchId: $enrollment->batch_id,
                    supervisor: $supervisor,
                    weekStart: $weekStart,
                    weekEnd: $weekEnd,
                    narrative: $narrative,
                    status: $status,
                    comment: $status === 'returned'
                        ? self::RETURN_COMMENTS[$returnedSoFar++ % count(self::RETURN_COMMENTS)]
                        : null,
                );
            }

            $studentIndex++;
        }
    }

    /**
     * A wall-clock time as every MDC user experiences it, converted to
     * whatever timezone the app is configured for.
     *
     * NOT `$date->setTime(21, 0)`. That writes 21:00 in the APP's timezone,
     * and `config('app.timezone')` defaults to UTC — deployments set
     * Asia/Manila, local dev usually does not. On a UTC box a 21:00 seed is
     * 21:00Z, which the SPA renders in the viewer's own timezone as 5am the
     * NEXT DAY: every "Submitted" date in the review queue lands one day
     * late. Anchoring to Asia/Manila is correct under both configs, because
     * it describes the moment rather than a number on a clock.
     */
    private function manila(Carbon $date, int $hour, int $minute): Carbon
    {
        return Carbon::create($date->year, $date->month, $date->day, $hour, $minute, 0, 'Asia/Manila')
            ->setTimezone(config('app.timezone'));
    }

    /**
     * Mon-Fri submitted entries for one week.
     *
     * @return array<string, string> entry_date => daily_accomplishment
     */
    private function seedWeekEntries(User $student, int $batchId, Carbon $weekStart, int $studentIndex, int $weekIndex): array
    {
        $accomplishments = [];

        foreach (range(0, 4) as $dayOffset) {
            $date = $weekStart->copy()->addDays($dayOffset);

            // Rotate the vocabulary by student AND by week so no two interns
            // and no two weeks read the same.
            $slot = ($studentIndex * 11) + ($weekIndex * 5) + $dayOffset;
            $text = self::ACCOMPLISHMENTS[$slot % count(self::ACCOMPLISHMENTS)];

            JournalEntry::updateOrCreate(
                ['student_id' => $student->id, 'entry_date' => $date->toDateString()],
                [
                    'batch_id' => $batchId,
                    'content' => [
                        'daily_accomplishment' => $text,
                        'task_performed' => $text,
                        'issues_concerns' => self::ISSUES[$slot % count(self::ISSUES)],
                        'solutions' => self::SOLUTIONS[$slot % count(self::SOLUTIONS)],
                        'recommendations' => self::RECOMMENDATIONS[$slot % count(self::RECOMMENDATIONS)],
                    ],
                    'status' => 'submitted',
                    'submitted_at' => $this->manila($date, 20, 30),
                ]
            );

            $accomplishments[$date->toDateString()] = $text;
        }

        return $accomplishments;
    }

    /**
     * Mirrors WeeklyBundlingService::compileNarrative() — "MONDAY\n<text>"
     * blocks separated by a blank line, no time range, days with nothing
     * skipped entirely. Kept in step with that method on purpose: a seeded
     * narrative that does not look like a compiled one would demo a document
     * format the app never actually produces.
     *
     * @param  array<string, string>  $entries
     */
    private function compileNarrative(Carbon $weekStart, array $entries): string
    {
        $dayNames = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
        $blocks = [];

        foreach ($dayNames as $offset => $dayName) {
            $text = trim($entries[$weekStart->copy()->addDays($offset)->toDateString()] ?? '');

            if ($text === '') {
                continue;
            }

            $blocks[] = "{$dayName}\n{$text}";
        }

        return implode("\n\n", $blocks);
    }

    private function seedWeeklyLog(
        User $student,
        int $batchId,
        User $supervisor,
        Carbon $weekStart,
        Carbon $weekEnd,
        string $narrative,
        string $status,
        ?string $comment,
    ): void {
        $isDraft = $status === 'draft';

        // whereDate, never plain equality: week_start is a `date`-cast column
        // and SQLite hands it back with a time component, so `where()` would
        // miss the previous run's row and insert a duplicate.
        $existing = WeeklyLog::where('student_id', $student->id)
            ->where('batch_id', $batchId)
            ->whereDate('week_start', $weekStart->toDateString())
            ->first();

        $attributes = [
            'week_end' => $weekEnd->toDateString(),
            'narrative' => $narrative,
            // A never-submitted draft still carries the DB default 'pending';
            // submitted_at is what distinguishes the two, not status.
            'status' => $isDraft ? 'pending' : $status,
            'submitted_at' => $isDraft ? null : $this->manila($weekEnd->copy()->addDay(), 21, 0),
            'supervisor_id' => in_array($status, ['approved', 'returned'], true) ? $supervisor->id : null,
            'reviewed_at' => in_array($status, ['approved', 'returned'], true)
                ? $this->manila($weekEnd->copy()->addDays(2), 10, 30)
                : null,
            'supervisor_comment' => $comment,
        ];

        if ($existing) {
            $existing->update($attributes);

            return;
        }

        WeeklyLog::create($attributes + [
            'student_id' => $student->id,
            'batch_id' => $batchId,
            'week_start' => $weekStart->toDateString(),
        ]);
    }
}
