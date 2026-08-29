<?php

namespace Database\Seeders;

use App\Models\BatchStudent;
use App\Models\StudentExitInterview;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Exit interview demo data for mdcbalbero (CABM-B), so the coordinator's
 * "Student Exit Interviews" page has real forms to open, filter and download
 * the moment the database is seeded — and so each of the three memorable
 * mdcbalintern* logins finds their own form in whichever state is worth
 * showing.
 *
 * THE THREE STATES ARE THE POINT, not the count. The page's Status filter and
 * the coordinator's own compliance block are only demonstrable if all three
 * exist side by side:
 *
 *   mdcbalintern1  reviewed   — coordinator's block already filled in, with a
 *                               pending requirement, so the ☐ With pending
 *                               requirements box is ticked on the printed PDF
 *   mdcbalintern2  submitted  — waiting for the coordinator: the one row that
 *                               actually needs them to do something
 *   mdcbalintern3  draft      — started, not handed in; shows that a draft is
 *                               visible to the coordinator but cannot be
 *                               signed off (the PUT 422s), which is the rule
 *                               worth seeing rather than reading about
 *
 * Answers are written in three distinct voices so a coordinator paging through
 * them is reading three students rather than the same paragraph three times,
 * and every one is short enough to fit the printed rules (the form gives each
 * question five ruled lines, and question 7 only four).
 *
 * Timestamps go through interviewedOn(), which anchors them in Asia/Manila
 * rather than ->setTime() — setTime() writes the APP's timezone, UTC by
 * default here and Asia/Manila on a deployment, so an afternoon stamp lands
 * eight hours out (the bug CabmbSupervisorDtrDemoSeeder documents). It also
 * clamps the date to the past: the batch runs on beyond today, so a naive
 * "start + 10 weeks" printed a FUTURE interview date onto a form a
 * coordinator signs by hand.
 *
 * Re-runnable: keyed on the (student, batch) pair that the table's own unique
 * index enforces.
 */
class CabmbExitInterviewDemoSeeder extends Seeder
{
    /**
     * Which intern, in which state, in which voice.
     */
    private const PLAN = [
        ['username' => 'mdcbalintern1', 'state' => 'reviewed', 'voice' => 'operations'],
        ['username' => 'mdcbalintern2', 'state' => 'submitted', 'voice' => 'accounts'],
        ['username' => 'mdcbalintern3', 'state' => 'draft', 'voice' => 'frontline'],
    ];

    /**
     * Three complete sets of answers. Every string is deliberately under the
     * width of the five printed rules it lands on.
     */
    private const ANSWERS = [
        'operations' => [
            'q1' => 'Posted daily transactions, prepared client statements, filed loan folders and reconciled the petty cash fund before the branch closed each afternoon.',
            'q2' => 'Almost everything I did came straight out of our financial management subjects, especially the reconciliation and the loan documentation work.',
            'q2_choice' => 'yes',
            'q3' => 'Core banking entry, spreadsheet reconciliation, preparing amortisation schedules, and filing loan documents to the bank\'s own retention standard.',
            'q4' => 'Communication with walk-in clients, time management across two desks, and simply being professional about mistakes instead of hiding them.',
            'q5' => 'Time management. Two desks needed me at once most mornings and I had to learn to say which one I would finish first.',
            'q6' => 'Demanding but genuinely worthwhile. I was treated as part of the team from the second week rather than as somebody watching.',
            'q7' => 'My supervisor checked my output at the end of every day and walked me through anything unfamiliar before assigning it.',
            'q7_choice' => 'yes',
            'q8' => 'The volume in the first two weeks was overwhelming. I started writing a checklist the night before, which turned a panic into a routine.',
            'q9' => 'That accuracy matters more than speed in a bank, and that asking a question early costs far less than fixing an error later.',
            'q10' => 'It confirmed that I want to work in branch operations rather than in a back office, which I had not expected before this internship.',
            'q10_choice' => 'yes',
            'q11' => 'I still want more practice with the loan approval side, but I am confident about the day-to-day work.',
            'q11_choice' => 'yes',
            'q12' => 'Being placed with a supervisor who actually gave me real work. The weekly journal also made me notice what I had learned.',
            'q13' => 'A short orientation on the core banking system before the first day would have saved me most of my first week.',
            'q14' => 'Ask questions on day one while it is still expected of you, and write your journal the same day rather than at the weekend.',
        ],
        'accounts' => [
            'q1' => 'Encoded supplier invoices, helped prepare the monthly bank reconciliation, and maintained the accounts payable schedule for the branch.',
            'q2' => 'Yes. The bookkeeping and reconciliation work was the same material as our accounting subjects, only faster and with real consequences.',
            'q2_choice' => 'yes',
            'q3' => 'Spreadsheet functions I had never used, the bank\'s accounting system, and how a reconciliation is actually assembled from source documents.',
            'q4' => 'Attention to detail, working steadily under a deadline, and asking for a review before submitting rather than after.',
            'q5' => 'Attention to detail, easily. A single transposed figure meant redoing a whole schedule, and it only had to happen once.',
            'q6' => 'A steady, well-supervised experience. The work was repetitive at times but it was real work that somebody depended on.',
            'q7' => 'Yes. My supervisor reviewed my schedules weekly and explained the corrections rather than simply making them.',
            'q7_choice' => 'yes',
            'q8' => 'I fell behind on the payables schedule during the month-end rush. I asked for help early the second time, and it never happened again.',
            'q9' => 'That documentation is the job, not the paperwork around it. If it is not filed and traceable, it may as well not have been done.',
            'q10' => 'I now intend to sit the board exam and work in audit, which I was still undecided about when I started.',
            'q10_choice' => 'yes',
            'q11' => 'Yes, for entry-level accounting work. I would still want supervision on anything involving statutory filings.',
            'q11_choice' => 'yes',
            'q12' => 'The exposure to a full month-end close. Nothing in class prepares you for the pace of it.',
            'q13' => 'More coordination between the school and the company about what we are allowed to touch. It took two weeks to settle.',
            'q14' => 'Keep your own copy of every schedule you prepare. You will be asked about it weeks later.',
        ],
        'frontline' => [
            'q1' => 'Assisted at the client service desk, prepared new account forms, and helped clients complete their deposit and withdrawal slips.',
            'q2' => 'Partly. The client-facing work was excellent practice, though less of it related directly to my financial management subjects.',
            'q2_choice' => 'yes',
            'q3' => 'Handling the queueing system, preparing account opening documents, and verifying identification against the bank\'s checklist.',
            'q4' => 'Patience, listening properly before answering, and staying composed with a client who is already annoyed before reaching my desk.',
            'q5' => 'Communication. I began the internship afraid to speak to strangers and ended it explaining requirements without notes.',
            'q6' => 'Challenging at the start and enjoyable by the end. Being at the front desk meant I could not hide, which turned out to be the point.',
            'q7' => 'Yes, though the desk was often busy. My supervisor made time at the end of each day to go through what I had handled.',
            'q7_choice' => 'yes',
            'q8' => 'A client shouted at me in my first week over a requirement I had explained correctly. My supervisor showed me how to defuse it.',
            'q9' => 'That most complaints are about not being listened to rather than about the rule itself.',
            'q10' => 'It made me consider client relations rather than a purely numbers role, which is a genuine change of direction for me.',
            'q10_choice' => 'yes',
            'q11' => 'Mostly. I would like more exposure to the products side before I apply for a permanent frontline position.',
            'q11_choice' => 'no',
            'q12' => 'Being put in front of real clients rather than kept in a back room. It is the only way that skill develops.',
            'q13' => 'A short briefing on the bank\'s products in the first week. I learned them by being asked, which was not comfortable.',
            'q14' => 'Do not be afraid of the front desk. It is the fastest way to learn and the part you will remember.',
        ],
    ];

    public function run(): void
    {
        foreach (self::PLAN as $plan) {
            $student = User::where('username', $plan['username'])->first();

            if (! $student) {
                continue;
            }

            // The placement this form belongs to. Active or completed both
            // count, matching the controller's own currentEnrollment() rule.
            $enrollment = BatchStudent::where('student_id', $student->id)
                ->whereIn('status', ['active', 'completed'])
                ->with('batch')
                ->latest('enrolled_at')
                ->first();

            if (! $enrollment || ! $enrollment->batch) {
                continue;
            }

            $responses = self::ANSWERS[$plan['voice']];

            // A draft is a form somebody stopped halfway through, so seed it
            // that way rather than as a complete form that merely has not been
            // pressed — the page's "Draft" row should look like one.
            if ($plan['state'] === 'draft') {
                $responses = array_intersect_key($responses, array_flip(['q1', 'q2', 'q2_choice', 'q3']));
            }

            $interviewedOn = $this->interviewedOn($enrollment->batch->start_date);

            StudentExitInterview::updateOrCreate(
                ['student_id' => $student->id, 'batch_id' => $enrollment->batch_id],
                [
                    'student_info' => [
                        'department_position' => $enrollment->assigned_division ?: 'Branch Operations',
                        'total_hours' => $plan['state'] === 'draft' ? null : '486',
                        'date_of_interview' => $plan['state'] === 'draft' ? null : $interviewedOn->toDateString(),
                    ],
                    'responses' => $responses,
                    'coordinator_section' => $plan['state'] === 'reviewed' ? [
                        'compliance' => 'pending',
                        'pending_detail' => 'Final narrative report and company certificate still to be submitted.',
                        'remarks' => 'Endorsed for clearance once the two outstanding documents are filed with the department office.',
                    ] : null,
                    'submission_status' => $plan['state'],
                    'submitted_at' => $plan['state'] === 'draft' ? null : $interviewedOn,
                    'reviewed_at' => $plan['state'] === 'reviewed' ? $interviewedOn->copy()->addDays(2) : null,
                    'reviewed_by' => $plan['state'] === 'reviewed' ? $enrollment->batch->coordinator_id : null,
                ]
            );
        }
    }

    /**
     * When the interview was held: late in the placement, but NEVER in the
     * future and never before the batch began.
     *
     * A seeded date after today would print on a form a coordinator signs by
     * hand — the same reasoning CabmbWeeklyTimeLogDemoSeeder applies to its
     * weekly rows, and a real defect this seeder hit first time round: the
     * batch runs on past today, so "start + 10 weeks" landed a week into next
     * month.
     */
    private function interviewedOn(Carbon $batchStart): Carbon
    {
        // Built in Asia/Manila and stored as the moment it describes. Never
        // ->setTime(), which writes the APP's timezone — UTC by default here,
        // Asia/Manila on a deployment — and renders eight hours out.
        $afternoon = fn (Carbon $day) => Carbon::create(
            $day->year, $day->month, $day->day, 14, 30, 0, 'Asia/Manila'
        );

        $late = $batchStart->copy()->addWeeks(10);
        $recent = Carbon::now('Asia/Manila')->subDays(3);

        return $afternoon($late->lessThan($recent) ? $late : $recent->max($batchStart));
    }
}
