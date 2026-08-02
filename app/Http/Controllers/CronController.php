<?php

namespace App\Http\Controllers;

use App\Console\Commands\PurgeArchivedBatchStudents;
use App\Console\Commands\RunWeeklyBundling;
use App\Console\Commands\SendMissingJournalEntryReminders;
use App\Models\SystemSetting;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * HTTP entry point for the scheduled work in routes/console.php, so a host with
 * no cron (the decided deploy stack is Render's free tier, which has none) can
 * still run it by being pinged from a free external scheduler.
 *
 * It deliberately does NOT call `schedule:run`. That command only fires a task
 * whose cron expression matches the CURRENT MINUTE, and an external pinger
 * cannot promise the minute it lands on — cron-job.org jitters, and a sleeping
 * Render instance adds 30-60s of cold start on top. A ping at 10:03 would find
 * the `hourly()` task (0 * * * *) not due and silently do nothing, every hour,
 * forever. Each job below is instead invoked directly behind a guard that
 * tolerates an imprecise arrival time.
 */
class CronController extends Controller
{
    private const PURGE_MARKER = 'cron_last_purge_at';

    private const BUNDLING_MARKER = 'cron_last_bundling_at';

    public function run(Request $request): JsonResponse
    {
        $this->authorizeRequest($request);

        $now = Carbon::now();
        $ran = [];

        // Reminders — run on EVERY ping, no marker. The command self-gates twice
        // over: it skips any student whose chosen hour is not the current hour,
        // and dedupes on (user, title, today) so a student can never be mailed
        // twice in a day. That makes it safe at any ping frequency and, more
        // importantly, correct at any ping MINUTE — which is exactly what a
        // schedule:run-based trigger could not offer.
        $ran['reminders'] = $this->invoke(SendMissingJournalEntryReminders::class);

        // Archive purge — idempotent (it deletes rows already past their 30-day
        // cutoff), so the daily marker is about not doing pointless work rather
        // than about safety.
        if ($this->isDue(self::PURGE_MARKER, $now->copy()->startOfDay())) {
            $ran['purge'] = $this->invoke(PurgeArchivedBatchStudents::class);
            $this->stamp(self::PURGE_MARKER, $now);
        }

        // Weekly bundling — the marker here IS load-bearing, not an optimisation.
        // Bundling freely overwrites any unsubmitted WeeklyLog draft, and nothing
        // distinguishes "we auto-filled this" from "the student edited it", so
        // running it on every ping would wipe a student's in-progress narrative
        // once an hour. It may run at most once per calendar week, and only from
        // Monday onward, since it compiles the Mon-Sun week that just ended.
        if ($this->isDue(self::BUNDLING_MARKER, $now->copy()->startOfWeek())) {
            $ran['bundling'] = $this->invoke(RunWeeklyBundling::class);
            $this->stamp(self::BUNDLING_MARKER, $now);
        }

        Log::info('Cron endpoint ran', ['tasks' => array_keys($ran)]);

        return response()->json([
            'ran_at' => $now->toIso8601String(),
            'tasks' => $ran,
        ]);
    }

    /**
     * 404 rather than 401/403 on a bad or missing key, so probing the URL cannot
     * confirm the endpoint exists. An unset CRON_SECRET disables the endpoint
     * entirely — without that guard a blank secret would compare equal to a
     * blank key and leave the trigger wide open on any unconfigured deployment.
     */
    private function authorizeRequest(Request $request): void
    {
        $secret = (string) config('services.cron.secret');

        if (trim($secret) === '') {
            abort(404);
        }

        // Header preferred; query string supported because several free cron
        // services can only issue a plain GET with no custom headers.
        $provided = (string) ($request->header('X-Cron-Key') ?? $request->query('key', ''));

        if (! hash_equals($secret, $provided)) {
            Log::warning('Cron endpoint rejected a request', ['ip' => $request->ip()]);
            abort(404);
        }
    }

    /**
     * @return array{exit_code: int, summary: string}
     */
    private function invoke(string $command): array
    {
        $exitCode = Artisan::call($command);

        // Only the trailing summary line, never the per-student lines above it:
        // the response body is stored in the cron provider's execution history,
        // and those lines name individual students.
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", Artisan::output())),
            fn (string $line) => $line !== ''
        ));

        return [
            'exit_code' => $exitCode,
            'summary' => $lines === [] ? '' : end($lines),
        ];
    }

    private function isDue(string $key, CarbonInterface $threshold): bool
    {
        $raw = SystemSetting::query()->where('key', $key)->value('value');

        if ($raw === null || trim((string) $raw) === '') {
            return true;
        }

        try {
            return Carbon::parse($raw)->lt($threshold);
        } catch (\Throwable) {
            // A hand-edited or corrupted marker must not wedge the job forever.
            return true;
        }
    }

    private function stamp(string $key, CarbonInterface $at): void
    {
        SystemSetting::updateOrCreate(['key' => $key], ['value' => $at->toIso8601String()]);
        SystemSetting::forgetCache();
    }
}
