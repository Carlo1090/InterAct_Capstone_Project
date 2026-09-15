<?php

namespace Database\Seeders;

use App\Models\BatchStudent;
use App\Models\StudentExitInterview;
use App\Models\User;
use App\Support\ExitInterview\ExitInterviewForms;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Exit interview demo data for mdccore (CAST), on the CAST department's own
 * form — the counterpart of CabmbExitInterviewDemoSeeder, so the second
 * hardcoded form is visible end to end the moment the database is seeded:
 * the student's page renders 31 questions and a rating row instead of 14
 * questions and Yes/No pairs, the coordinator's Summary Report tallies the
 * rating, and the PDF paginates onto four pages rather than two.
 *
 * Same three states, for the same reason that seeder gives:
 *
 *   mdcstudent   submitted  — waiting for mdccore's compliance block
 *   mdcstudent2  reviewed   — the coordinator's block already filled in
 *   mdcstudent3  draft      — started, not handed in; cannot be signed off
 *
 * Answers are written in two voices, both short enough to fit the five ruled
 * lines each question prints. The interview is stamped `form_key = 'cast'`
 * explicitly rather than resolved, because DatabaseSeeder runs
 * WithoutModelEvents and nothing here goes through the Form Request that
 * would otherwise stamp it.
 *
 * Re-runnable: keyed on the (student, batch) pair the table's own unique
 * index enforces.
 */
class CastExitInterviewDemoSeeder extends Seeder
{
    private const PLAN = [
        ['username' => 'mdcstudent', 'state' => 'submitted', 'voice' => 'developer'],
        ['username' => 'mdcstudent2', 'state' => 'reviewed', 'voice' => 'support'],
        ['username' => 'mdcstudent3', 'state' => 'draft', 'voice' => 'developer'],
    ];

    private const ANSWERS = [
        'developer' => [
            'q1' => 'Challenging at first, then genuinely rewarding. By the third week I was trusted with real tickets rather than watching someone else close them.',
            'q2' => 'Working inside a real codebase with a review process, and being on the receiving end of a code review from a senior developer.',
            'q3' => 'Git branching and pull requests, writing unit tests before a fix, reading logs to trace a bug, and basic SQL query tuning.',
            'q4' => 'Fixing a date bug that had been open for months. It was small, but it was the first time my change reached actual users.',
            'q5' => 'Yes. Almost everything came from our programming and database subjects, especially the web development and database design classes.',
            'q6' => 'Understanding a large codebase nobody had documented, and asking for help without feeling like I was interrupting.',
            'q7' => 'I kept a notes file of every module I touched and asked one focused question at a time instead of a vague one.',
            'q8' => 'Quiet, organised and friendly. Everyone had clear tasks and the team stand-up each morning kept the day predictable.',
            'q9' => 'Yes. The first two days were an orientation on the tools, the coding standards and who to ask for what.',
            'q10' => 'Mostly. I was given a workstation and accounts on the first day; the only wait was for a licence on one design tool.',
            'q11' => 'Professional and approachable. My supervisor reviewed my work daily and explained the reasoning behind every change she asked for.',
            'q12' => 'Yes. Feedback was specific and prompt, usually inside the pull request itself, so I could fix it the same day.',
            'q13' => 'Yes. I was introduced as a member of the team and invited to every meeting the developers attended.',
            'q14' => 'Yes. The programming, database and software engineering subjects were all directly usable on the job.',
            'q15' => 'Object-oriented programming, SQL, and the software engineering process, especially requirements and testing.',
            'q16' => 'Estimating how long a task will take, and reading other people\'s code quickly.',
            'q17' => 'Yes. Having real changes merged and deployed made me trust that I can contribute to a working system, not just a class project.',
            'q18' => 'Yes. I saw how much of a developer\'s day is communication, review and maintenance rather than writing new code.',
            'q19' => 'Ownership of my work, punctuality, and being honest about what I did not understand.',
            'q20' => 'Yes. Daily stand-ups improved how I report progress, and pairing with a tester improved how I describe a problem.',
            'q21' => 'That deadlines are commitments other people plan around, and that professionalism is mostly consistency.',
            'q22' => 'Yes. I now want to start in backend development rather than in a general IT support role.',
            'q28' => 'Being trusted with real work and seeing it reach users.',
            'q29' => 'The first two weeks of feeling lost in the codebase before the orientation notes made sense.',
            'q30' => 'A short written guide to the codebase for incoming interns, so the first weeks are less about guessing.',
            'q31' => 'Ask early, write things down, and treat every code review as a lesson rather than a judgement.',
            'q32' => 'Yes. The team takes interns seriously and gives real tasks, which is the whole point of an OJT.',
            'q33' => 'very_good',
            'q35' => 'That software is a team effort, and clear communication matters as much as clean code.',
            'q36' => 'Automated testing, cloud deployment, and estimating work more accurately.',
            'q37' => 'Thank you to the department for the placement. It changed how I see the profession.',
        ],
        'support' => [
            'q1' => 'A steep but fair learning curve. I rotated through the help desk and the network team and finished with a clear idea of what I enjoy.',
            'q2' => 'Handling real users with real problems, and learning to stay calm when the answer is not obvious.',
            'q3' => 'Ticketing systems, basic network troubleshooting, Active Directory account management and documenting fixes.',
            'q4' => 'Setting up the new laptops for a department move. It touched everything I had learned and had a visible result.',
            'q5' => 'Yes. Networking, operating systems and the systems administration subjects were used every day.',
            'q6' => 'Prioritising when several users needed help at once, and explaining technical things to non-technical staff.',
            'q7' => 'I learned to triage by impact first and to write a two-sentence plain-language summary before touching anything.',
            'q8' => 'Busy but supportive. The IT office is small, so everyone helps everyone and there was always somebody to ask.',
            'q9' => 'Yes. I was walked through the ticket system, the escalation rules and the office policies on my first morning.',
            'q10' => 'Yes. I had my own desk, a test machine and access to the knowledge base from the start.',
            'q11' => 'Very good. My supervisor treated me like a junior staff member and checked in every afternoon.',
            'q12' => 'Yes. He reviewed my ticket notes weekly and pointed out where a fix could have been documented better.',
            'q13' => 'Yes. I was never asked to do anything outside the training plan and my questions were always taken seriously.',
            'q14' => 'Yes, particularly networking and operating systems, which were the basis of most tickets.',
            'q15' => 'Network fundamentals and the systems administration subjects, plus the technical writing class.',
            'q16' => 'Deeper network diagnostics and scripting, which the senior staff used constantly.',
            'q17' => 'Yes. By the end I could close most first-line tickets without asking, which I could not have done in week one.',
            'q18' => 'Yes. I understand now that support is as much about people and follow-through as about hardware.',
            'q19' => 'Patience, reliability, and taking responsibility for a ticket until it is genuinely resolved.',
            'q20' => 'Yes. Writing clear ticket notes improved my communication, and coordinating with the network team improved teamwork.',
            'q21' => 'That showing up on time and closing what you open is most of what professionalism means in practice.',
            'q22' => 'Somewhat. I am now considering network administration as a specialisation instead of general IT.',
            'q28' => 'The variety of problems and how much I learned from each one.',
            'q29' => 'Some weeks were repetitive when the ticket queue was mostly password resets.',
            'q30' => 'Rotate interns between teams earlier, so the exposure is wider from the first month.',
            'q31' => 'Document every fix you make; it is the fastest way to learn and it helps the next intern.',
            'q32' => 'Yes. It is a well-run office that gives interns real responsibility with proper supervision.',
            'q33' => 'excellent',
            'q35' => 'That the user\'s problem is the job, not the technology behind it.',
            'q36' => 'Scripting, network security fundamentals and professional certification.',
            'q37' => 'Nothing further, except that I would happily return there after graduation.',
        ],
    ];

    public function run(): void
    {
        $form = ExitInterviewForms::get('cast');

        foreach (self::PLAN as $plan) {
            $student = User::where('username', $plan['username'])->first();

            if (! $student) {
                continue;
            }

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
            // that way rather than as a complete form that merely has not
            // been pressed.
            if ($plan['state'] === 'draft') {
                $responses = array_intersect_key($responses, array_flip(['q1', 'q2', 'q3', 'q4']));
            }

            $interviewedOn = $this->interviewedOn($enrollment->batch->start_date);

            StudentExitInterview::updateOrCreate(
                ['student_id' => $student->id, 'batch_id' => $enrollment->batch_id],
                [
                    'form_key' => $form->key,
                    'student_info' => [
                        'department_position' => $enrollment->assigned_division ?: 'IT Department',
                        'total_hours' => $plan['state'] === 'draft' ? null : '486',
                        'date_of_interview' => $plan['state'] === 'draft' ? null : $interviewedOn->toDateString(),
                    ],
                    'responses' => $responses,
                    'coordinator_section' => $plan['state'] === 'reviewed' ? [
                        'compliance' => 'complete',
                        'pending_detail' => null,
                        'remarks' => 'All OJT requirements submitted. Endorsed for clearance.',
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
     * Late in the placement, never in the future and never before the batch
     * began; built in Asia/Manila — see CabmbExitInterviewDemoSeeder for why
     * neither ->setTime() nor a naive "start + N weeks" is safe here.
     */
    private function interviewedOn(Carbon $batchStart): Carbon
    {
        $afternoon = fn (Carbon $day) => Carbon::create(
            $day->year, $day->month, $day->day, 15, 0, 0, 'Asia/Manila'
        );

        $late = $batchStart->copy()->addWeeks(10);
        $recent = Carbon::now('Asia/Manila')->subDays(4);

        return $afternoon($late->lessThan($recent) ? $late : $recent->max($batchStart));
    }
}
