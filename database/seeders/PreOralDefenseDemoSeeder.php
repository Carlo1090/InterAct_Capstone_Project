<?php

namespace Database\Seeders;

use App\Models\JournalEntry;
use App\Models\User;
use App\Models\WeeklyLog;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * One-off, additive demo-data pass for the 2026-08-06 pre-oral defense.
 *
 * Deliberately NOT registered in DatabaseSeeder::run() — run it directly:
 *   php artisan db:seed --class=PreOralDefenseDemoSeeder
 *
 * It never touches migrate:fresh territory and never drops anything. Every
 * write below is either an update to a row a prior seeder already created
 * (CabmbUsersDemoSeeder / CabmbWeeklyDemoSeeder), or an update to the
 * project owner's own live account (matched by email, never recreated).
 * Fully re-runnable/idempotent — safe to run again before or during the
 * defense without duplicating or corrupting anything.
 *
 * What this does, and why:
 *   1. Renames the CABM-B/Metrobank login-supervisor's username to
 *      "demosupervisor" (by request) — only the username column changes,
 *      not the email other seeders key off of, so nothing else breaks.
 *   2. Fills the project owner's own two draft weekly narratives (BSA @
 *      Dunkin' Bohol) with real text, but leaves them UNSUBMITTED so he can
 *      demo the Submit flow live, from his own verified-email account.
 *   3. Gives the CABM-B demo student (mdcbalberostudent / Renz Corvera) a
 *      full Mon-Fri week of submitted daily entries for 2026-07-27, the
 *      exact week WeeklyBundlingService::mostRecentlyCompletedWeekStart()
 *      resolves to as of 2026-08-05/06 — and makes sure that week's
 *      WeeklyLog is genuinely empty/unsubmitted, so running
 *      `php artisan journal:run-weekly-bundling` live on stage visibly
 *      compiles it from blank.
 *   4. Marks a spread of already-existing weekly narratives (written by
 *      CabmbUsersDemoSeeder-era data) as submitted, with varied statuses
 *      (pending / approved / returned) across all four CABM-B programs —
 *      so Coordinator Balbero's Weekly Journals page and every CABM-B
 *      supervisor's review queue have real rows to show, not an empty list.
 *   5. Enriches the OTHER supervisor login (mdcsupervisor / Engr. Ramon
 *      Villanueva, CAST/BSIT, TechPH Inc.) — his module had only two
 *      already-actioned historical weeks (one approved, one returned) and
 *      nothing left to actually review, plus a blank student profile.
 *      Gives him a fresh PENDING week to approve/return live, a second
 *      APPROVED week for history, and fills in the blank contact/DOB/
 *      address fields on Maria Santos's profile so the Intern Detail modal
 *      isn't empty.
 */
class PreOralDefenseDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->renameFmSupervisorLogin();
        $this->fillOwnerDraftNarratives();
        $this->prepareLiveBundlingWeek();
        $this->seedPastWeekReviewSpread();
        $this->enrichVillanuevaSupervisorModule();
    }

    /**
     * Rename the FM/Metrobank login supervisor's username to
     * "demosupervisor", per explicit request. Matched by email (set by
     * CabmbUsersDemoSeeder) so this is safe to re-run even if the username
     * has already been changed.
     */
    private function renameFmSupervisorLogin(): void
    {
        User::where('email', 'cabmb.sup.fm@gmail.com')
            ->update(['username' => 'demosupervisor']);
    }

    /**
     * Fill the project owner's own draft weekly narratives so he has real
     * content to review before pressing Submit live during the defense.
     * Deliberately leaves submitted_at NULL — do not submit on his behalf.
     */
    private function fillOwnerDraftNarratives(): void
    {
        $owner = User::where('email', 'marklloydaleria@gmail.com')->first();

        if (! $owner) {
            return;
        }

        $narratives = [
            '2026-07-20' => <<<'TEXT'
MONDAY
Reported to Dunkin' Bohol and shadowed the store accountant while she reconciled the previous week's sales report against the POS system's end-of-day totals.

TUESDAY
Assisted in counting and recording petty cash disbursements for the week and helped file supplier receipts by date for the upcoming inventory audit.

WEDNESDAY
Sat in on a briefing about the branch's monthly cost-of-goods-sold computation and helped tally raw material usage sheets submitted by the kitchen staff.

THURSDAY
Helped prepare the weekly sales summary spreadsheet for submission to the area accountant, cross-checking figures against the manual cash register tapes.

FRIDAY
Observed the payroll preparation process for part-time crew and assisted in verifying attendance logs against the biometric time records before encoding.
TEXT,
            '2026-07-27' => <<<'TEXT'
MONDAY
Assisted the branch accountant in preparing the month-end bank reconciliation, matching deposit slips against the online banking portal's transaction history.

TUESDAY
Helped organize supplier invoices for the upcoming BIR compliance filing and learned how the branch tracks input VAT on ingredient purchases.

WEDNESDAY
Participated in a physical inventory count of packaging supplies and assisted in updating the stock ledger to reflect actual counts versus system records.

THURSDAY
Reviewed void and discount transaction logs with the shift supervisor to flag any entries requiring manager approval, part of the branch's internal control routine.

FRIDAY
Assisted in consolidating the week's financial summary for the area office report and sat in on a short briefing on how variance from target sales is explained in the report.
TEXT,
        ];

        foreach ($narratives as $weekStart => $narrative) {
            $log = WeeklyLog::where('student_id', $owner->id)
                ->whereDate('week_start', $weekStart)
                ->first();

            if (! $log) {
                continue;
            }

            // Leave status/submitted_at untouched — this must stay a draft
            // he submits himself, live.
            $log->update(['narrative' => $narrative]);
        }
    }

    /**
     * Ensure the CABM-B demo student has a full, submitted Mon-Fri week of
     * daily entries for 2026-07-27 (the "most recently completed week" as
     * of the defense date), and that the corresponding WeeklyLog is blank
     * and unsubmitted — ready to be compiled live via
     * `php artisan journal:run-weekly-bundling` on stage.
     */
    private function prepareLiveBundlingWeek(): void
    {
        $student = User::where('username', 'mdcbalberostudent')->first();

        if (! $student) {
            return;
        }

        $batchId = \App\Models\BatchStudent::where('student_id', $student->id)
            ->where('status', 'active')
            ->value('batch_id');

        if (! $batchId) {
            return;
        }

        $weekStart = Carbon::parse('2026-07-27');

        $dailyAccomplishments = [
            "Assisted the branch accountant in preparing the month-end bank reconciliation, matching deposit slips against the online banking portal's transaction history.",
            'Helped organize supplier invoices for the upcoming compliance filing and learned how the branch tracks documentation for recurring vendor payments.',
            'Participated in a physical count of office supplies and assisted in updating the branch inventory ledger to reflect the actual counts.',
            "Reviewed a batch of loan disbursement vouchers with the operations officer to confirm supporting documents were complete before the branch manager's sign-off.",
            'Assisted in consolidating the week\'s transaction summary for the regional report and sat in on a short briefing on the branch\'s monthly targets.',
        ];

        foreach ($dailyAccomplishments as $offset => $text) {
            $entryDate = $weekStart->copy()->addDays($offset);

            $entry = JournalEntry::where('student_id', $student->id)
                ->whereDate('entry_date', $entryDate)
                ->first();

            $attributes = [
                'batch_id' => $batchId,
                'content' => [
                    'task_performed' => $text,
                    'daily_accomplishment' => $text,
                ],
                'status' => 'submitted',
                'submitted_at' => $entryDate->copy()->setTime(21, 0),
            ];

            if ($entry) {
                $entry->update($attributes);
            } else {
                JournalEntry::create([
                    'student_id' => $student->id,
                    'entry_date' => $entryDate->toDateString(),
                    ...$attributes,
                ]);
            }
        }

        // Reset the week's WeeklyLog to a genuinely blank, unsubmitted
        // state so the live bundling command has something visible to do.
        WeeklyLog::where('student_id', $student->id)
            ->where('batch_id', $batchId)
            ->whereDate('week_start', $weekStart)
            ->update([
                'narrative' => '',
                'status' => 'pending',
                'submitted_at' => null,
                'reviewed_at' => null,
                'supervisor_comment' => null,
            ]);
    }

    /**
     * Mark a spread of already-narrated (but never submitted) weekly logs
     * as submitted, with varied review statuses, across all four CABM-B
     * programs — so Coordinator Balbero's Weekly Journals page and the
     * per-company supervisor review queues have real, distinct rows.
     */
    private function seedPastWeekReviewSpread(): void
    {
        // [username or name-match key, week_start, target status, comment]
        $plan = [
            // BSBA-FM — Metrobank, supervisor demosupervisor (Dennis Chua)
            ['name' => 'Renz Adrian Corvera', 'week' => '2026-07-13', 'status' => 'approved', 'comment' => null],
            ['name' => 'Karlo Mendoza', 'week' => '2026-07-13', 'status' => 'pending', 'comment' => null, 'seedNarrative' => true, 'company' => 'Metrobank'],

            // BSA — Bohol Quality Corporation, supervisor Carmela Uy
            ['name' => 'Andrea Villanueva', 'week' => '2026-07-13', 'status' => 'returned', 'comment' => 'Please add more specific detail on the reconciliation tasks you assisted with — this reads a bit too general for the weekly report. Kindly revise and resubmit.'],
            ['name' => 'Miguel Torres', 'week' => '2026-07-13', 'status' => 'approved', 'comment' => null],
            ['name' => 'Carlos Diaz', 'week' => '2026-07-20', 'status' => 'approved', 'comment' => null],

            // BSBA-MM — supervisors Grace Lim / Trisha Yap
            ['name' => 'Patricia Cruz', 'week' => '2026-07-13', 'status' => 'approved', 'comment' => null, 'seedNarrative' => true, 'company' => 'Alturas Group of Companies'],

            // BSBA-OM — supervisors Paolo Reyes / Leo Amper
            ['name' => 'Sophia Reyes', 'week' => '2026-07-13', 'status' => 'pending', 'comment' => null, 'seedNarrative' => true, 'company' => 'Island City Mall Management'],
        ];

        $fallbackNarratives = [
            'Metrobank' => <<<'TEXT'
MONDAY
Assisted the operations officer in verifying deposit slips against the teller's daily transaction log as part of the branch's end-of-day reconciliation.

TUESDAY
Helped encode client loan application documents into the branch system and organized supporting files for the credit evaluation team.

WEDNESDAY
Sat in on a client onboarding session and assisted in preparing account-opening forms, checking submitted IDs against the compliance checklist.

THURSDAY
Helped compile the branch's weekly cash flow summary and learned how figures are reported up to the regional office.

FRIDAY
Assisted with filing cleared checks and observed the branch's process for balancing the vault at the close of business.
TEXT,
            'Alturas Group of Companies' => <<<'TEXT'
MONDAY
Assisted the marketing team in compiling the week's foot-traffic and sales data across the mall's tenant directory for the weekly management report.

TUESDAY
Helped organize promotional event documentation and coordinated with the operations desk on signage placement for an upcoming tenant sale.

WEDNESDAY
Sat in on a vendor coordination meeting and took notes on lease-renewal discussion points for the property management file.

THURSDAY
Assisted in reviewing tenant complaint logs and helped draft a summary for the property manager's weekly rounds.

FRIDAY
Helped prepare the weekend event checklist and coordinated with security and janitorial staff on the schedule for an upcoming promotional activity.
TEXT,
            'Island City Mall Management' => <<<'TEXT'
MONDAY
Assisted the operations team in reviewing the mall's weekly maintenance request log and helped prioritize items for the facilities crew.

TUESDAY
Helped monitor tenant compliance with mall operating hours and documented observations for the operations manager's weekly report.

WEDNESDAY
Sat in on a briefing about the mall's upcoming fire-safety drill and assisted in preparing the floor-warden assignment sheet.

THURSDAY
Assisted in compiling the weekly parking utilization report used to plan traffic flow during peak hours.

FRIDAY
Helped coordinate with tenants on an upcoming mall-wide promotional weekend, assisting with the setup checklist and signage inventory.
TEXT,
        ];

        foreach ($plan as $item) {
            $student = User::where('name', $item['name'])->where('role', 'student')->first();

            if (! $student) {
                continue;
            }

            $log = WeeklyLog::where('student_id', $student->id)
                ->whereDate('week_start', $item['week'])
                ->first();

            if (! $log) {
                continue;
            }

            $update = [
                'status' => $item['status'],
                'submitted_at' => Carbon::parse($item['week'])->addDays(4)->setTime(20, 0),
            ];

            if (! empty($item['seedNarrative']) && empty($log->narrative)) {
                $update['narrative'] = $fallbackNarratives[$item['company']] ?? '';
            }

            if (in_array($item['status'], ['approved', 'returned'], true)) {
                $update['supervisor_id'] = $log->supervisor_id ?? $this->companySupervisorFor($student->id, $log->batch_id);
                $update['reviewed_at'] = Carbon::parse($item['week'])->addDays(5)->setTime(10, 0);
            }

            if ($item['status'] === 'returned') {
                $update['supervisor_comment'] = $item['comment'];
            }

            $log->update($update);
        }
    }

    private function companySupervisorFor(int $studentId, int $batchId): ?int
    {
        return \App\Models\BatchStudent::where('student_id', $studentId)
            ->where('batch_id', $batchId)
            ->value('supervisor_id');
    }

    /**
     * Engr. Ramon Villanueva (mdcsupervisor, TechPH Inc.) only had two
     * already-actioned historical weeks — nothing left for him to review
     * live, and a blank student profile on Maria Santos. Gives him one
     * fresh PENDING week (Juan Dela Cruz, week of 2026-07-20 — expanding
     * his existing 2-day stub into a full Mon-Fri narrative) and one more
     * APPROVED week (Maria Santos, same week) for a fuller Recently
     * Reviewed history.
     */
    private function enrichVillanuevaSupervisorModule(): void
    {
        $supervisor = User::where('username', 'mdcsupervisor')->first();
        $juan = User::where('name', 'Juan Dela Cruz')->first();
        $maria = User::where('name', 'Maria Santos')->first();

        if (! $supervisor || ! $juan || ! $maria) {
            return;
        }

        $juanLog = WeeklyLog::where('student_id', $juan->id)
            ->whereDate('week_start', '2026-07-20')
            ->first();

        if ($juanLog) {
            $juanLog->update([
                'narrative' => <<<'TEXT'
MONDAY
Wrote unit tests for the reporting module and fixed two failing assertions flagged by the CI pipeline.

TUESDAY
Paired with a senior developer to refactor the export-to-PDF service, focusing on reducing duplicate query calls.

WEDNESDAY
Attended the sprint planning meeting and picked up a ticket to add input validation to the client intake form.

THURSDAY
Implemented the validation rules from Wednesday's ticket and wrote accompanying test cases for edge cases like empty and malformed input.

FRIDAY
Reviewed API error handling with the supervisor and updated the error-response format to match the team's documented API standard.
TEXT,
                'status' => 'pending',
                'submitted_at' => Carbon::parse('2026-07-20')->addDays(4)->setTime(19, 30),
                'reviewed_at' => null,
                'supervisor_comment' => null,
            ]);
        }

        $mariaLog = WeeklyLog::where('student_id', $maria->id)
            ->whereDate('week_start', '2026-07-20')
            ->first();

        if ($mariaLog) {
            $mariaLog->update([
                'narrative' => <<<'TEXT'
MONDAY
Assisted in testing the mobile app's login flow across three device sizes and logged two layout bugs in the issue tracker.

TUESDAY
Shadowed the QA lead during a regression testing session for the upcoming release and helped document the test cases covered.

WEDNESDAY
Updated the user-facing help documentation to match the new settings page layout that shipped this sprint.

THURSDAY
Assisted in triaging incoming bug reports and helped reproduce three of them for the development team.

FRIDAY
Sat in on the sprint retrospective and helped compile the team's notes into the shared summary document.
TEXT,
                'status' => 'approved',
                'submitted_at' => Carbon::parse('2026-07-20')->addDays(4)->setTime(19, 45),
                'supervisor_id' => $supervisor->id,
                'reviewed_at' => Carbon::parse('2026-07-20')->addDays(5)->setTime(9, 0),
            ]);
        }

        \App\Models\StudentProfile::where('user_id', $maria->id)->update([
            'contact_number' => '09182345678',
            'sex' => $maria->studentProfile?->sex ?? 'female',
            'date_of_birth' => '2003-02-18',
            'home_address' => 'Purok 2, Barangay Poblacion, Tagbilaran City, Bohol',
        ]);
    }
}
