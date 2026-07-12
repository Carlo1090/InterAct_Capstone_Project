<?php

namespace Database\Seeders;

use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\WeeklyLog;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Gives the demo supervisor (mdcsupervisor@gmail.com) at least one intern with
 * a SUBMITTED weekly narrative log awaiting review, plus daily journal entries
 * that week for review context, so the supervisor pages are non-empty.
 * Fully re-runnable.
 */
class SupervisorReviewDemoSeeder extends Seeder
{
    public function run(): void
    {
        $supervisor = User::where('email', 'mdcsupervisor@gmail.com')->first();

        if (! $supervisor) {
            return;
        }

        $enrollments = BatchStudent::where('supervisor_id', $supervisor->id)
            ->get()
            ->take(2);

        $weekStart = now()->subWeeks(2)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addDays(6);

        foreach ($enrollments as $enrollment) {
            // Daily journal entries within the review week (context for the supervisor).
            foreach (range(0, 4) as $offset) {
                JournalEntry::updateOrCreate(
                    ['student_id' => $enrollment->student_id, 'entry_date' => $weekStart->copy()->addDays($offset)->toDateString()],
                    [
                        'batch_id' => $enrollment->batch_id,
                        'content' => [
                            'task_performed' => 'Worked on assigned module tasks and documented progress.',
                            'daily_accomplishment' => 'Worked on assigned module tasks and documented progress.',
                            'issues_concerns' => 'A configuration mismatch slowed the local build.',
                            'solutions' => 'Aligned the environment file with the senior engineer.',
                        ],
                        'status' => 'submitted',
                        'submitted_at' => $weekStart->copy()->addDays($offset)->setTime(21, 0),
                    ]
                );
            }

            WeeklyLog::updateOrCreate(
                ['student_id' => $enrollment->student_id, 'batch_id' => $enrollment->batch_id, 'week_start' => $weekStart->toDateString()],
                [
                    'supervisor_id' => $supervisor->id,
                    'week_end' => $weekEnd->toDateString(),
                    'status' => 'pending',
                    'supervisor_comment' => null,
                    'submitted_at' => $weekEnd->copy()->addDay()->setTime(21, 0),
                    'reviewed_at' => null,
                    'narrative' => 'This week I focused on the core module tasks, joined the daily stand-ups, and completed the assigned documentation. '
                        .'I hit a configuration issue mid-week that was resolved with the senior engineer\'s guidance, and I applied the fix across the affected services.',
                ]
            );
        }
    }
}
