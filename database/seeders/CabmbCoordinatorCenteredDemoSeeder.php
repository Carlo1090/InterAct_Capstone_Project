<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\JournalEntry;
use App\Models\JournalTemplate;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\WeeklyLog;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A COORDINATOR-CENTERED cohort under mdcbalbero, so both OJT types are
 * demonstrable side by side on fresh demo data.
 *
 * Every other seeded batch is supervisor-supported, which is correct — that is
 * the default and how most cohorts run. But it left `batches.ojt_type` with
 * exactly one value anywhere in the demo set, so the Journal Review page was
 * empty for every account, the coordinator's own Approve/Return could not be
 * seen at all, and the batch list's OJT Type column showed one pill repeated
 * down the page. The contrast IS the feature; one value cannot show it.
 *
 * Deliberately a SEPARATE batch, company and roster rather than flipping
 * mdcbalsup's cohort: flipping it would strip that supervisor's entire world
 * (their roster, queue, notebooks and time records all resolve through
 * `supervisedEnrollments()`, which excludes rows with no supervisor), trading
 * one empty demo for another. Side by side, one login shows each.
 *
 * What this produces:
 *
 *   mdcbalbero (coordinator)  →  Journal Review has 3 interns, all three tabs
 *                                non-empty, and a full notebook per intern
 *   mdcfield1 / 2 / 3         →  students whose weeks were approved BY THEIR
 *                                COORDINATOR, not by a company supervisor
 *
 * Three things are load-bearing:
 *
 * 1. **The company has NO login-bearing supervisor**, only a named contact.
 *    That is the whole point of the mode — enrollment succeeds where a
 *    supervisor-supported batch would 422 — and it means the demo proves the
 *    branch in EnrollmentService rather than just displaying a different pill.
 * 2. **`supervisor_id` is null on every enrollment**, exactly as
 *    EnrollmentService writes it. A seeded non-null value would put these
 *    interns back on some supervisor's roster and quietly contradict the rule
 *    `ScopesSupervisorWork` enforces.
 * 3. **The reviewer on approved/returned logs is the COORDINATOR.**
 *    `weekly_logs.supervisor_id` records who gave the verdict; seeding a
 *    supervisor there would show a review that could not have happened.
 *
 * Timestamps are anchored to Asia/Manila, never `->setTime()`, for the reason
 * CabmbSupervisorJournalDemoSeeder documents at length. Re-runnable: the log is
 * located by (student, batch, week_start) with **whereDate()**, since
 * `week_start` is a `date`-cast column and SQLite keeps a time component on it.
 */
class CabmbCoordinatorCenteredDemoSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'password';

    private const COMPANY = 'Bohol Provincial Cooperative Development Office';

    private const BATCH = 'BSBA-OM 2026 Field Placement';

    private const WEEKS = 5;

    /**
     * Per-intern week plan, oldest first. 'draft' is compiled but never handed
     * in — what WeeklyBundlingService leaves every Monday, and what the
     * notebook deliberately hides while still counting it in Week N.
     *
     * @var array<string, array<int, string>>
     */
    private const PLAN = [
        'mdcfield1' => ['approved', 'approved', 'returned', 'pending', 'draft'],
        'mdcfield2' => ['approved', 'approved', 'approved', 'pending', 'pending'],
        'mdcfield3' => ['approved', 'returned', 'approved', 'pending', 'draft'],
    ];

    /** @var array<int, string> */
    private const ACCOMPLISHMENTS = [
        'Joined the field team visiting three cooperatives in Dauis and recorded their membership counts.',
        'Encoded the accomplishment reports submitted by the cooperatives visited last week.',
        'Helped prepare the checklist used during the cooperative compliance visits.',
        'Sat in on the orientation given to a newly registered cooperative in Baclayon.',
        'Consolidated the quarterly reports of eight cooperatives into the office summary sheet.',
        'Assisted in filing the annual general assembly minutes submitted by member cooperatives.',
        'Prepared the travel itinerary and documents for the following week\'s monitoring visits.',
        'Encoded the results of the cooperative self-assessment forms into the tracking sheet.',
        'Helped the officer draft the notice of deficiency for two cooperatives with late filings.',
        'Observed a mediation session between a cooperative board and a complaining member.',
        'Updated the master list of registered cooperatives with their current contact persons.',
        'Assisted in preparing the training materials for the bookkeeping seminar.',
        'Recorded attendance and distributed handouts at the cooperative bookkeeping seminar.',
        'Reviewed the submitted financial statements against the office\'s completeness checklist.',
        'Helped sort and archive the previous year\'s cooperative registration folders.',
        'Encoded the monitoring visit findings and flagged the cooperatives needing follow-up.',
        'Drafted the summary of the field visits for the officer\'s weekly report.',
        'Assisted in verifying the membership rosters submitted for the annual report.',
        'Helped prepare certificates of compliance for release to five cooperatives.',
        'Observed how the office handles a request for cooperative dissolution.',
        'Encoded the barangay-level cooperative data into the provincial database.',
        'Helped organise the documents required for the upcoming provincial cooperative congress.',
        'Assisted in checking the liquidation reports submitted after the seminar.',
        'Updated the tracking sheet for cooperatives with pending regulatory submissions.',
        'Prepared the file copies of the monitoring reports for the officer\'s signature.',
    ];

    /** @var array<int, string> */
    private const ISSUES = [
        'Two of the cooperatives we visited had no one available to receive the monitoring team.',
        'Several submitted reports were incomplete, so they could not be encoded as received.',
        'The office database was slow, which delayed encoding the field visit findings.',
        'Contact numbers on the master list were outdated for several cooperatives.',
        'Travel to the far barangays took most of the morning and shortened the visit time.',
    ];

    /** @var array<int, string> */
    private const SOLUTIONS = [
        'Left the checklist with the officer on duty and scheduled a return visit.',
        'Listed the missing attachments and forwarded the list to the officer for follow-up.',
        'Recorded the findings on the paper form and encoded them once the system recovered.',
        'Noted the outdated entries and updated them from the latest submitted reports.',
        'Suggested grouping the far barangays into one trip and raised it with the team lead.',
    ];

    /** @var array<int, string> */
    private const RECOMMENDATIONS = [
        'Confirming the visit by phone the day before would avoid an unreceived trip.',
        'A completeness check at the receiving desk would save a round of follow-up letters.',
        'An offline field form would keep monitoring work moving when the database is slow.',
        'Contact details could be confirmed at every submission to keep the list current.',
        'Clustering the distant barangays into a single route would recover half a day.',
    ];

    /** @var array<int, string> */
    private const RETURN_COMMENTS = [
        'Please expand Tuesday and Thursday — say what you actually found during the monitoring visits, not just that you joined them. Add the issue you raised about the unreceived visit.',
        'Good detail on the seminar, but Monday and Friday are too thin to show a full day. Please add the encoding work you handled before resubmitting.',
    ];

    public function run(): void
    {
        $coordinator = User::where('username', 'mdcbalbero')->first();

        if (! $coordinator) {
            return;
        }

        $program = Program::where('code', 'BSBA-OM')->first();

        if (! $program) {
            return;
        }

        // Every other seeded batch carries a journal template, and this one
        // silently did not — so its daily entries had no section list to be
        // ordered or labelled by, and the coordinator's own Journal Review (the
        // ONE review surface this cohort has) fell back to raw JSON key order
        // with humanised labels. That is exactly the surface this batch exists
        // to demonstrate. BSBA-OM is a CABM-B program, so it takes the CABM-B
        // template like its supervisor-supported siblings; the entries seeded
        // below already use that template's keys.
        $template = JournalTemplate::where('name', 'CABM-B Daily Journal Template')->first();

        $batch = Batch::updateOrCreate(
            ['name' => self::BATCH],
            [
                'program_id' => $program->id,
                'coordinator_id' => $coordinator->id,
                'journal_template_id' => $template?->id,
                'ojt_type' => Batch::OJT_TYPE_COORDINATOR,
                'academic_year' => '2026',
                'semester' => 'Internship',
                'start_date' => Carbon::today()->startOfWeek(Carbon::MONDAY)->subWeeks(self::WEEKS + 1),
                'end_date' => Carbon::today()->addMonths(2),
                'required_hours' => 486,
                'working_days_per_week' => 5,
                'daily_reminder_time' => '21:00:00',
                'is_active' => true,
            ]
        );

        // NO login-bearing supervisor, deliberately — only a named contact, who
        // is informational and prints on the paper forms. A supervisor-supported
        // batch would 422 on every enrollment here.
        $company = Company::firstOrCreate(
            ['name' => self::COMPANY],
            [
                'address' => 'Capitol Compound, Tagbilaran City, Bohol',
                'location' => 'Tagbilaran City, Bohol',
                'industry' => 'Government / Cooperative Development',
                'contact_number' => '038-412-3355',
                'head_name' => 'Ms. Teresita Ranario',
                'head_contact_number' => '038-412-3356',
                'head_email' => 'pcdo@bohol.example',
                'department_head' => 'Cooperative Development',
                'description' => 'Provincial office hosting BSBA-OM interns on field monitoring work, with no on-site supervisor account.',
                'is_active' => true,
            ]
        );

        CompanySupervisor::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Ms. Teresita Ranario'],
            ['user_id' => null, 'position' => 'Cooperative Development Officer IV']
        );

        $interns = [
            ['username' => 'mdcfield1', 'name' => 'Aljon Pahang', 'sid' => '2022-OM-201', 'sex' => 'male'],
            ['username' => 'mdcfield2', 'name' => 'Marnelli Quiro', 'sid' => '2022-OM-202', 'sex' => 'female'],
            ['username' => 'mdcfield3', 'name' => 'Dexter Gallentes', 'sid' => '2022-OM-203', 'sex' => 'male'],
        ];

        $latestWeek = Carbon::today()->startOfWeek(Carbon::MONDAY)->subWeek();
        $batchStart = $batch->start_date?->copy()->startOfDay();
        $studentIndex = 0;

        foreach ($interns as $intern) {
            $student = User::updateOrCreate(
                ['username' => $intern['username']],
                [
                    'name' => $intern['name'],
                    'email' => $intern['username'].'@gmail.com',
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'role' => 'student',
                    'student_id_number' => $intern['sid'],
                    'program_id' => $program->id,
                    'is_active' => true,
                    'must_change_password' => false,
                ]
            );

            // DatabaseSeeder runs WithoutModelEvents, which mutes the
            // UserObserver that would normally create this row.
            StudentProfile::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'student_id_number' => $intern['sid'],
                    'sex' => $intern['sex'],
                    'total_hours_required' => 486,
                ]
            );

            // supervisor_id stays NULL — exactly what EnrollmentService writes
            // for a coordinator-centered batch, and what keeps these interns
            // off every supervisor surface.
            BatchStudent::updateOrCreate(
                ['batch_id' => $batch->id, 'student_id' => $student->id],
                [
                    'company_id' => $company->id,
                    'supervisor_id' => null,
                    'company_supervisor_id' => null,
                    'assigned_division' => 'Cooperative Monitoring',
                    'status' => 'active',
                ]
            );

            $returnedSoFar = 0;

            foreach (self::PLAN[$intern['username']] as $index => $status) {
                $weekStart = $latestWeek->copy()->subWeeks(self::WEEKS - 1 - $index);
                $weekEnd = $weekStart->copy()->addDays(6);

                if ($batchStart && $weekEnd->lt($batchStart)) {
                    continue;
                }

                $entries = $this->seedWeekEntries($student, $batch->id, $weekStart, $studentIndex, $index);

                $this->seedWeeklyLog(
                    student: $student,
                    batchId: $batch->id,
                    reviewer: $coordinator,
                    weekStart: $weekStart,
                    weekEnd: $weekEnd,
                    narrative: $this->compileNarrative($weekStart, $entries),
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
     * A wall-clock time as every MDC user experiences it. NOT `->setTime()` —
     * see CabmbSupervisorJournalDemoSeeder::manila() for why that writes a
     * morning shift that renders as an afternoon one on a UTC box.
     */
    private function manila(Carbon $date, int $hour, int $minute): Carbon
    {
        return Carbon::create($date->year, $date->month, $date->day, $hour, $minute, 0, 'Asia/Manila')
            ->setTimezone(config('app.timezone'));
    }

    /**
     * @return array<string, string> entry_date => daily_accomplishment
     */
    private function seedWeekEntries(User $student, int $batchId, Carbon $weekStart, int $studentIndex, int $weekIndex): array
    {
        $accomplishments = [];

        foreach (range(0, 4) as $dayOffset) {
            $date = $weekStart->copy()->addDays($dayOffset);
            $slot = ($studentIndex * 9) + ($weekIndex * 5) + $dayOffset;
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
     * Mirrors WeeklyBundlingService::compileNarrative() — a seeded narrative
     * that does not look compiled would demo a document format the app never
     * produces.
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

            $blocks[] = $dayName."\n".$text;
        }

        return implode("\n\n", $blocks);
    }

    private function seedWeeklyLog(
        User $student,
        int $batchId,
        User $reviewer,
        Carbon $weekStart,
        Carbon $weekEnd,
        string $narrative,
        string $status,
        ?string $comment,
    ): void {
        $isDraft = $status === 'draft';
        $isReviewed = in_array($status, ['approved', 'returned'], true);

        // whereDate(), never plain equality — week_start is a date-cast column
        // and SQLite keeps a time component on it, so equality misses the
        // previous run's row and inserts a duplicate.
        $log = WeeklyLog::where('student_id', $student->id)
            ->where('batch_id', $batchId)
            ->whereDate('week_start', $weekStart->toDateString())
            ->first();

        $attributes = [
            'batch_id' => $batchId,
            'student_id' => $student->id,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'narrative' => $narrative,
            'status' => $isDraft ? 'pending' : $status,
            'submitted_at' => $isDraft ? null : $this->manila($weekEnd->copy()->addDay(), 9, 15),
            // The COORDINATOR is the reviewer here. Seeding a supervisor would
            // show a review that could not have happened on this batch.
            'supervisor_id' => $isReviewed ? $reviewer->id : null,
            'reviewed_at' => $isReviewed ? $this->manila($weekEnd->copy()->addDays(2), 14, 0) : null,
            'supervisor_comment' => $comment,
        ];

        if ($log) {
            $log->update($attributes);

            return;
        }

        WeeklyLog::create($attributes);
    }
}
