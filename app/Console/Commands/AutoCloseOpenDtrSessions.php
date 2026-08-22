<?php

namespace App\Console\Commands;

use App\Models\DtrSession;
use App\Models\Notification;
use App\Services\DtrService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Close every DTR session a student left open, and tell them it happened.
 *
 * Without this a forgotten clock-out never ends. The next morning's scan is
 * read as a clock-OUT of yesterday, so the student believes they timed in,
 * walks away, and their evening scan opens another overnight session — the
 * failure compounds a day at a time and nothing surfaces it to anyone.
 *
 * DtrService::punch() carries the same guard, so the toggle stays correct even
 * when this never runs (the API sleeps on an idle free tier and the external
 * cron jitters). This command is what makes the close happen at a predictable
 * hour instead of whenever the student next scans, and it is the only path
 * that notifies them.
 *
 * Idempotent and self-gating: it selects only status='open' past the stale
 * threshold, and closing leaves 'flagged', so a re-run finds nothing. That is
 * why CronController may invoke it on every ping with no marker — unlike
 * weekly bundling, where a re-run destroys a student's draft.
 */
class AutoCloseOpenDtrSessions extends Command
{
    /**
     * Fixed, because it is what the student sees in their bell row. Kept as a
     * constant so a test can assert on it without retyping the wording.
     */
    public const TITLE = 'Time Record Automatically Closed';

    protected $signature = 'dtr:auto-close-sessions
                            {--now= : Treat this timestamp as the current time (testing/manual runs).}';

    protected $description = 'Close DTR sessions left open past the stale threshold and notify the student.';

    public function handle(DtrService $dtr): int
    {
        $now = $this->option('now') ? Carbon::parse($this->option('now')) : Carbon::now();

        $sessions = $dtr->staleOpenSessions($now)
            ->with(['student:id,name', 'geofence:id,label'])
            ->orderBy('time_in')
            ->get();

        $reason = DtrService::staleReason();
        $closed = 0;

        foreach ($sessions as $session) {
            // A coordinator may have switched DTR off since this session was
            // opened. Close it anyway — leaving a student permanently unable
            // to clock in, should the switch come back on, is the worse
            // outcome, and the session's minutes still do not count.
            $dtr->autoCloseStaleSession($session, $reason);
            $this->notifyStudent($session);
            $closed++;

            // Named lines stay ABOVE the summary: CronController::invoke()
            // returns only the trailing line, and the cron provider stores
            // that response body in its execution history.
            $this->line("Closed session #{$session->id} for {$session->student?->name} ({$session->work_date?->toDateString()}).");
        }

        $this->info("Auto-closed {$closed} session(s).");

        return self::SUCCESS;
    }

    /**
     * In-app only. There is no email here on purpose: this is a correction to
     * a record the student can see on their own DTR page, not something that
     * needs to reach them wherever they are.
     */
    private function notifyStudent(DtrSession $session): void
    {
        // 'in_app' is mandatory — notifications.type is a strict enum
        // (email/push/in_app) and a wrong value fails an SQLite CHECK
        // constraint rather than validating.
        Notification::create([
            'user_id' => $session->student_id,
            'title' => self::TITLE,
            'message' => sprintf(
                'Your %s time record at %s was closed automatically because no clock-out was scanned. '
                .'It does not count toward your hours yet — ask your supervisor to confirm how long you worked.',
                $session->work_date?->format('M j') ?? 'open',
                $session->geofence?->label ?? 'your workplace',
            ),
            'type' => 'in_app',
            'is_read' => false,
            // Written explicitly rather than relying on the column's
            // useCurrent() default, which uses the DATABASE clock and ignores
            // Carbon test-time travel.
            'sent_at' => now(),
        ]);
    }
}
