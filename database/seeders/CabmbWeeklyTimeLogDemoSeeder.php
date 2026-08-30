<?php

namespace Database\Seeders;

use App\Models\BatchStudent;
use App\Models\CompanySupervisor;
use App\Models\User;
use App\Models\WeeklyActivityEntry;
use App\Models\WeeklyActivityLog;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Weekly Activity Log and Time Log Summary demo data for mdcbalbero (CABM-B),
 * so the coordinator's read-only "Weekly and Time Log Summary" page has real
 * sheets to open, filter and download the moment the database is seeded.
 *
 * SIX students across ALL FOUR of her programs get a sheet, deliberately —
 * five would satisfy the count, but spreading them over BSA / BSBA-FM /
 * BSBA-MM / BSBA-OM is what makes the page's Program filter mean anything.
 * Three of the six are the memorable `mdcbalintern1..3` logins from
 * CabmbSupervisorDemoSeeder, so the same roster is reachable as coordinator,
 * as their supervisor (mdcbalsup) and as each student.
 *
 * THE PERIOD COVERED IS THREE MONTHS — one sheet spanning the whole internship
 * quarter (13 weeks), which is what 486 SIPP hours actually works out to at
 * 8h/day. It is anchored to the BATCH's own start_date rather than to now(), so
 * the period always lands inside the batch window no matter when the database
 * is seeded, and it is a whole number of weeks so the weekly rows tile it
 * exactly.
 *
 * Only weeks that have actually FINISHED get a row. A sheet is therefore
 * partially complete — the declared period runs to the end of the quarter while
 * the rows stop at last Friday — which is precisely what a coordinator sees
 * when they open one mid-placement. Seeding rows with dates in the future would
 * be visibly wrong on a form a supervisor signs by hand.
 *
 * Two students also get a second, single-week sheet so "one student, several
 * sheets" and the page's Period From / Period To filter both have something to
 * act on.
 *
 * Re-runnable: the sheet is looked up by (student, batch, week_start) and rows
 * by (sheet, sort_order), and any row left over from a shorter previous run is
 * pruned. The week_start lookup uses whereDate(), never a plain equality match
 * — week_start is a `date`-cast column and SQLite stores it WITH a time
 * component, so plain equality would miss the row and insert a duplicate.
 */
class CabmbWeeklyTimeLogDemoSeeder extends Seeder
{
    /** 13 weeks ≈ 3 months, and a whole number of weeks so the rows tile it. */
    private const WEEKS_IN_PERIOD = 13;

    /** A full Mon-Fri week at 8h/day — the figure a student types on the form. */
    private const HOURS_PER_WEEK = 40;

    /**
     * Which student gets a sheet, whose activity vocabulary it is written in,
     * and how many hours to shave off the arithmetic total so six sheets do not
     * all report the identical figure (a half-day here, a holiday there).
     */
    private const PLAN = [
        ['username' => 'mdcbalintern1', 'pool' => 'banking', 'rotate' => 0, 'hours_off' => 0, 'extra_week' => true],
        ['username' => 'mdcbalintern2', 'pool' => 'banking', 'rotate' => 4, 'hours_off' => 4, 'extra_week' => false],
        ['username' => 'mdcbalintern3', 'pool' => 'banking', 'rotate' => 8, 'hours_off' => 8, 'extra_week' => false],
        ['username' => 'cabmb.bsa1', 'pool' => 'accounting', 'rotate' => 0, 'hours_off' => 6, 'extra_week' => true],
        ['username' => 'cabmb.mm1', 'pool' => 'marketing', 'rotate' => 0, 'hours_off' => 2, 'extra_week' => false],
        ['username' => 'cabmb.om1', 'pool' => 'operations', 'rotate' => 0, 'hours_off' => 4, 'extra_week' => false],
    ];

    public function run(): void
    {
        $coordinator = User::where('username', 'mdcbalbero')->first();

        if (! $coordinator) {
            return;
        }

        foreach (self::PLAN as $def) {
            $student = User::where('username', $def['username'])->first();

            if (! $student) {
                continue;
            }

            $enrollment = BatchStudent::with('batch')
                ->where('student_id', $student->id)
                ->where('status', 'active')
                ->latest('enrolled_at')
                ->first();

            // Only seed sheets this coordinator can actually open — the page is
            // scoped by the batch's program, so a sheet outside her department
            // would be invisible and pointless.
            if (! $enrollment?->batch || (int) $enrollment->batch->coordinator_id !== (int) $coordinator->id) {
                continue;
            }

            $this->seedQuarterSheet($student, $enrollment, $def);

            if ($def['extra_week']) {
                $this->seedSingleWeekSheet($student, $enrollment, $def);
            }
        }
    }

    /**
     * The headline example: one sheet whose Period Covered spans three months.
     */
    private function seedQuarterSheet(User $student, BatchStudent $enrollment, array $def): void
    {
        // Monday of the batch's opening week, so weekly rows tile the period
        // exactly instead of starting mid-week.
        $periodStart = $enrollment->batch->start_date->copy()->startOfWeek(Carbon::MONDAY);
        $periodEnd = $periodStart->copy()->addWeeks(self::WEEKS_IN_PERIOD)->subDay();

        $rows = $this->weeklyRows($periodStart, self::WEEKS_IN_PERIOD, $def, $enrollment);

        $this->writeSheet($student, $enrollment, $periodStart, $periodEnd, $rows, [
            'no_of_hours' => max(0, (count($rows) * self::HOURS_PER_WEEK) - $def['hours_off']),
        ]);
    }

    /**
     * A second sheet covering just the most recently finished week, so the
     * coordinator's list shows a student with more than one sheet and the
     * Period From / Period To filter has two different spans to separate.
     */
    private function seedSingleWeekSheet(User $student, BatchStudent $enrollment, array $def): void
    {
        $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY)->subWeek();
        $periodStart = $enrollment->batch->start_date->copy()->startOfWeek(Carbon::MONDAY);

        // Nothing to add if the batch is younger than a full week.
        if ($weekStart->lessThan($periodStart)) {
            return;
        }

        // Pass the real week-of-placement so this later sheet does not open
        // with the orientation row.
        $rows = $this->weeklyRows(
            $weekStart,
            1,
            $def,
            $enrollment,
            (int) $periodStart->diffInWeeks($weekStart),
        );

        if ($rows === []) {
            return;
        }

        $this->writeSheet($student, $enrollment, $weekStart, $weekStart->copy()->addDays(6), $rows, [
            'no_of_hours' => self::HOURS_PER_WEEK,
        ]);
    }

    /**
     * One row per FINISHED Mon-Fri week inside the span. A week whose Friday has
     * not happened yet is left out — the sheet is a paper facsimile a supervisor
     * signs, so it must never claim work that has not been done.
     *
     * @return array<int, array<string, string>>
     */
    private function weeklyRows(Carbon $spanStart, int $weeks, array $def, BatchStudent $enrollment, int $weekOffset = 0): array
    {
        $pool = self::ACTIVITY_POOLS[$def['pool']];
        // Week one is ALWAYS the orientation row — three interns who started at
        // the same company on the same Monday genuinely did share that week.
        // Only weeks two onward rotate, which is what keeps their sheets from
        // being byte-identical without making the opening week nonsense.
        $orientation = $pool[0];
        $rest = array_slice($pool, 1);

        $today = Carbon::today();

        [$supervisorName, $supervisorPosition] = $this->signatory($enrollment);

        $rows = [];

        for ($week = 0; $week < $weeks; $week++) {
            $start = $spanStart->copy()->addWeeks($week);
            $end = $start->copy()->addDays(4); // Friday

            if ($end->greaterThan($today)) {
                break;
            }

            // Which week of the whole placement this is, so a sheet covering a
            // later span never opens with the orientation row.
            $placementWeek = $weekOffset + $week;

            $entry = $placementWeek === 0
                ? $orientation
                : $rest[(($placementWeek - 1) + $def['rotate']) % count($rest)];

            $rows[] = [
                'inclusive_date_start' => $start->toDateString(),
                'inclusive_date_end' => $end->toDateString(),
                'activities' => $entry[0],
                'documents_records' => $entry[1],
                'objectives' => $entry[2],
                'supervisor_name' => $supervisorName,
                'supervisor_position' => $supervisorPosition,
            ];
        }

        return $rows;
    }

    /**
     * The person who signs each row: the company's login supervisor, with their
     * position read off the company_supervisors row rather than hardcoded here,
     * so it stays right if a company's supervisor is ever swapped.
     *
     * @return array{0: string, 1: string}
     */
    private function signatory(BatchStudent $enrollment): array
    {
        $enrollment->loadMissing('supervisor:id,name');

        $position = CompanySupervisor::where('company_id', $enrollment->company_id)
            ->where('user_id', $enrollment->supervisor_id)
            ->value('position');

        return [
            $enrollment->supervisor?->name ?? '',
            $position ?? 'OJT Supervisor',
        ];
    }

    /**
     * Create-or-refresh one sheet and its rows.
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  array<string, mixed>  $attributes
     */
    private function writeSheet(
        User $student,
        BatchStudent $enrollment,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $rows,
        array $attributes,
    ): void {
        if ($rows === []) {
            return;
        }

        $payload = [
            'week_end' => $periodEnd->toDateString(),
            // Student-entered on the real form; there is nothing to derive it
            // from, so mirror the placement's assigned division.
            'area_assigned' => $enrollment->assigned_division ?: 'Branch Operations',
            ...$attributes,
        ];

        // whereDate(), never a plain where(): week_start is a date-cast column
        // and SQLite keeps a time component on it, so equality would miss the
        // row this seeder wrote on its previous run and insert a duplicate.
        $log = WeeklyActivityLog::where('student_id', $student->id)
            ->where('batch_id', $enrollment->batch_id)
            ->whereDate('week_start', $periodStart->toDateString())
            ->first();

        if ($log) {
            $log->update($payload);
        } else {
            $log = WeeklyActivityLog::create([
                'student_id' => $student->id,
                'batch_id' => $enrollment->batch_id,
                'week_start' => $periodStart->toDateString(),
                ...$payload,
            ]);
        }

        foreach ($rows as $index => $row) {
            WeeklyActivityEntry::updateOrCreate(
                ['weekly_activity_log_id' => $log->id, 'sort_order' => $index],
                $row,
            );
        }

        // Prune anything left over from a run that produced more rows than this
        // one, so a re-seed can never leave a stale week on the sheet.
        $log->entries()->where('sort_order', '>=', count($rows))->delete();
    }

    /**
     * Week-sized blocks of realistic work per program, 13 deep so a full
     * three-month period never repeats itself. Each is
     * [Activities, Document/Records, Objective/s] — the three free-text columns
     * of the printed form.
     */
    private const ACTIVITY_POOLS = [
        'banking' => [
            ['Orientation on branch operations, bank secrecy and the client data privacy policy.', 'Orientation packet; signed confidentiality undertaking', 'Understand branch structure and the conduct expected of an intern.'],
            ['Assisted the New Accounts desk in checking deposit application forms for completeness.', 'Deposit account application forms', 'Apply KYC documentary requirements to real applications.'],
            ['Sorted and filed cleared cheques and validated deposit slips for the day-end file.', 'Deposit slips; cleared cheque register', 'Practise accuracy and orderliness in transaction filing.'],
            ['Encoded loan application data into the branch tracking sheet under supervision.', 'Loan application forms; loan tracking sheet', 'Gain familiarity with the consumer loan documentary flow.'],
            ['Helped reconcile the teller cash-count summary against the day-end proof sheet.', 'Cash count sheets; day-end proof sheet', 'Observe how daily cash proofing detects and corrects variances.'],
            ['Prepared client statements of account for mailing and updated the release logbook.', 'Statements of account; releasing logbook', 'Practise handling client documents with proper custody control.'],
            ['Assisted in the monthly review of dormant accounts and flagged those for reactivation notices.', 'Dormant account listing', 'Learn how account status is monitored and acted on.'],
            ['Supported the Accounts Officer during a member orientation on savings products.', 'Product brochures; attendance sheet', 'Build confidence explaining financial products to members.'],
            ['Filed and indexed signature cards and updated the members master file.', 'Signature cards; members master file', 'Understand how member identity records are maintained.'],
            ['Assisted in preparing the weekly collection report for the branch manager.', 'Collection report; supporting official receipts', 'Practise summarising transaction data into a management report.'],
            ['Reviewed loan release documents against the approved terms before releasing.', 'Promissory notes; disclosure statements', 'Check documentary compliance before disbursement.'],
            ['Helped conduct the quarterly inventory of accountable forms.', 'Accountable forms inventory sheet', 'Observe internal controls over accountable forms.'],
            ['Assisted in preparing the branch month-end reports and filing the supporting schedules.', 'Month-end report package', 'Tie a month of daily transactions to the reports they produce.'],
        ],
        'accounting' => [
            ['Orientation on the accounting department, chart of accounts and the filing system.', 'Orientation packet; chart of accounts', 'Understand how the department is organised before handling records.'],
            ['Sorted, numbered and filed official receipts and sales invoices for the period.', 'Official receipts; sales invoices', 'Practise orderly source-document management.'],
            ['Encoded purchase invoices into the accounts payable worksheet under supervision.', 'Purchase invoices; AP worksheet', 'Apply the accounts payable recording cycle to live documents.'],
            ['Assisted in preparing check vouchers and matching them to their supporting documents.', 'Check vouchers; delivery receipts', 'Learn the disbursement voucher process and its controls.'],
            ['Helped perform the bank reconciliation for one of the company depository accounts.', 'Bank statements; bank reconciliation statement', 'Apply bank reconciliation procedures to real statements.'],
            ['Assisted in the physical count of store merchandise and reconciled it to the stock cards.', 'Inventory count sheets; stock cards', 'Observe how a physical count validates recorded inventory.'],
            ['Prepared schedules of accounts receivable and aged them by due date.', 'AR subsidiary ledger; aging schedule', 'Practise receivable aging and its use in collection follow-up.'],
            ['Filed BIR forms and the supporting attachments for the monthly tax filing.', 'BIR returns; withholding tax certificates', 'Familiarise with statutory filing requirements and deadlines.'],
            ['Assisted in posting journal entries to the general ledger under review.', 'Journal vouchers; general ledger', 'Apply double-entry posting in the company books.'],
            ['Helped prepare the payroll summary and checked timekeeping records against it.', 'Timekeeping records; payroll summary', 'Understand how attendance data becomes payroll cost.'],
            ['Assisted in preparing the schedule of prepaid expenses and their amortisation.', 'Prepaid expense schedule', 'Apply accrual concepts to real balances.'],
            ['Reviewed petty cash replenishment requests against the supporting receipts.', 'Petty cash vouchers; replenishment report', 'Observe the controls over petty cash custody.'],
            ['Assisted in compiling the month-end trial balance working file.', 'Trial balance; supporting schedules', 'See how a month of entries rolls up into the trial balance.'],
        ],
        'marketing' => [
            ['Orientation on the marketing department, brand standards and the promo calendar.', 'Brand guidelines; promo calendar', 'Understand the brand and the campaign cycle before contributing.'],
            ['Assisted in setting up merchandising displays for the mid-month promotion.', 'Planogram; display checklist', 'Apply visual merchandising standards on the sales floor.'],
            ['Helped conduct a customer intercept survey and tallied the responses.', 'Survey forms; response tally sheet', 'Practise gathering and summarising customer feedback.'],
            ['Drafted social media captions and product photo shot lists for review.', 'Content calendar; draft captions', 'Practise writing on-brand copy for a real audience.'],
            ['Assisted in monitoring competitor pricing and prepared a comparison sheet.', 'Price monitoring sheet', 'Learn how competitive pricing data informs promo decisions.'],
            ['Helped prepare and distribute promotional flyers for a store activation.', 'Flyer layout; distribution log', 'Experience below-the-line promotion execution.'],
            ['Assisted during a weekend mall activation and recorded the foot traffic count.', 'Activation report; foot traffic tally', 'Observe how an activation is staffed and measured.'],
            ['Consolidated weekly sales data by product line into a summary report.', 'Sales report; product line summary', 'Practise turning sales data into a readable summary.'],
            ['Assisted in the inventory of promotional materials and giveaways.', 'Promo material inventory sheet', 'Understand controls over marketing collateral.'],
            ['Helped prepare the presentation deck for the monthly marketing review.', 'Presentation deck; supporting charts', 'Practise communicating campaign results to management.'],
            ['Assisted in coordinating with the supplier for the point-of-sale display materials.', 'Supplier correspondence; POS material specs', 'Observe supplier coordination in campaign execution.'],
            ['Monitored the social media pages and drafted responses to customer queries.', 'Engagement log; drafted responses', 'Practise customer-facing communication under supervision.'],
            ['Assisted in preparing the post-campaign evaluation report.', 'Campaign evaluation report', 'Learn how a campaign is assessed against its objectives.'],
        ],
        'operations' => [
            ['Orientation on mall operations, tenant relations and emergency procedures.', 'Orientation packet; emergency manual', 'Understand the operations structure and safety protocols.'],
            ['Assisted in the daily inspection of common areas and logged the findings.', 'Daily inspection checklist', 'Practise systematic facility inspection.'],
            ['Helped receive and route tenant concerns to the responsible unit.', 'Tenant concern slips; routing log', 'Learn how tenant issues are captured and escalated.'],
            ['Assisted in monitoring janitorial and security deployment against the duty roster.', 'Duty roster; deployment monitoring sheet', 'Observe how outsourced services are supervised.'],
            ['Helped prepare the weekly utilities consumption monitoring report.', 'Meter readings; consumption report', 'Understand how facility costs are tracked.'],
            ['Assisted in the inventory of maintenance supplies and materials.', 'Supplies inventory sheet', 'Apply inventory control to operating supplies.'],
            ['Supported the coordination of a scheduled fire and earthquake drill.', 'Drill plan; participation report', 'Observe emergency preparedness in a public facility.'],
            ['Assisted in processing tenant work permits for renovation activities.', 'Work permit forms; contractor list', 'Learn the permit controls over tenant construction.'],
            ['Helped monitor parking operations and consolidated the daily ticket report.', 'Parking ticket report', 'Practise reconciling operational volume data.'],
            ['Assisted in preparing incident reports and following up on their resolution.', 'Incident report forms', 'Practise factual incident documentation.'],
            ['Helped coordinate a mall-wide event set-up with the tenants concerned.', 'Event layout; tenant advisory', 'Experience multi-party operational coordination.'],
            ['Assisted in the preventive maintenance scheduling of building equipment.', 'Preventive maintenance schedule', 'Understand planned versus reactive maintenance.'],
            ['Assisted in compiling the monthly operations report for management.', 'Monthly operations report', 'See how daily logs roll up into a management report.'],
        ],
    ];
}
