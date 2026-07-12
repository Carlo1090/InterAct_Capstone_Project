<?php

namespace Database\Seeders;

use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Seeder;

class SippDemoEntriesSeeder extends Seeder
{
    /**
     * Seed a handful of submitted journal entries carrying SIPP content
     * (issues_concerns / solutions / recommendations) for the demo student,
     * so the Coordinator Annual SIPP Report has candidate rows to curate.
     */
    public function run(): void
    {
        $student = User::where('email', 'mdcstudent@gmail.com')->first();

        if (! $student) {
            return;
        }

        $enrollment = BatchStudent::where('student_id', $student->id)
            ->where('status', 'active')
            ->first();

        if (! $enrollment) {
            return;
        }

        $entries = [
            [
                'days_ago' => 12,
                'content' => [
                    'task_performed' => 'Assisted in configuring the staging deployment pipeline.',
                    'daily_accomplishment' => 'Assisted in configuring the staging deployment pipeline.',
                    'issues_concerns' => 'The staging server frequently ran out of memory during builds, halting deployments.',
                    'solutions' => 'Increased the build container memory limit and cleared stale Docker image layers.',
                    'recommendations' => 'Schedule a weekly cleanup of unused build artifacts to prevent recurrence.',
                ],
            ],
            [
                'days_ago' => 9,
                'content' => [
                    'task_performed' => 'Wrote unit tests for the reporting module.',
                    'daily_accomplishment' => 'Wrote unit tests for the reporting module.',
                    'issues_concerns' => 'Test data setup was repetitive and error-prone across test files.',
                    'solutions' => 'Introduced a shared factory helper to standardize test fixtures.',
                    'recommendations' => 'Document the factory helpers so future interns adopt the same pattern.',
                ],
            ],
            [
                'days_ago' => 5,
                'content' => [
                    'task_performed' => 'Reviewed API error handling with the supervisor.',
                    'daily_accomplishment' => 'Reviewed API error handling with the supervisor.',
                    'issues_concerns' => 'Some endpoints returned inconsistent error shapes, confusing the frontend team.',
                    'solutions' => 'Adopted a single JSON error envelope across the affected endpoints.',
                    'recommendations' => 'Add a linting check so new endpoints follow the agreed error format.',
                ],
            ],
        ];

        foreach ($entries as $entry) {
            $date = now()->subDays($entry['days_ago'])->toDateString();

            JournalEntry::updateOrCreate(
                ['student_id' => $student->id, 'entry_date' => $date],
                [
                    'batch_id' => $enrollment->batch_id,
                    'content' => $entry['content'],
                    'status' => 'submitted',
                    'submitted_at' => now()->subDays($entry['days_ago']),
                ]
            );
        }
    }
}
