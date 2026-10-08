<?php

namespace Tests\Unit\Support;

use Tests\TestCase;

/**
 * Guards the timezone DEFAULT, not the configured value.
 *
 * The bug this exists for (reported 2026-10-08): the mobile write screen
 * offered YESTERDAY and refused today's date. `JournalEntryController`'s range
 * guard compares against `today()`, which resolves in `config('app.timezone')`
 * — and that config defaulted to UTC while every user of this system is in
 * Manila (UTC+8). Between Manila midnight and 08:00 the UTC date is still the
 * previous day, so for eight hours out of every twenty-four the server's
 * "today" was the student's yesterday: writing today's entry 422'd as "outside
 * your OJT range", and the newest writable date really was yesterday.
 *
 * It was a default rather than a typo, which is why it survived: the variable
 * was documented, declared in render.yaml and described in config/app.php's own
 * comment, but absent from the local .env — so correctness depended on a human
 * remembering it in two separate places, and forgetting produced a silent wrong
 * answer that healed itself at 8am rather than a loud failure.
 */
class AppTimezoneTest extends TestCase
{
    /**
     * phpunit.xml deliberately pins APP_TIMEZONE=UTC so the suite's
     * Carbon::setTestNow dates keep their original baseline — so asserting on
     * `config('app.timezone')` here would only re-read that pin and prove
     * nothing. This reloads config/app.php with the variable genuinely absent,
     * which is what a deployment that forgot to set it actually gets.
     */
    public function test_the_timezone_defaults_to_manila_when_the_env_var_is_missing(): void
    {
        $previous = [
            'env' => $_ENV['APP_TIMEZONE'] ?? null,
            'server' => $_SERVER['APP_TIMEZONE'] ?? null,
            'putenv' => getenv('APP_TIMEZONE'),
        ];

        unset($_ENV['APP_TIMEZONE'], $_SERVER['APP_TIMEZONE']);
        putenv('APP_TIMEZONE');

        try {
            $config = require base_path('config/app.php');

            $this->assertSame(
                'Asia/Manila',
                $config['timezone'],
                'With APP_TIMEZONE unset the app must still resolve dates in Manila time. '
                .'A UTC fallback puts every "what day is it" check a day behind for the first '
                .'eight hours of every Manila day.'
            );
        } finally {
            if ($previous['env'] !== null) {
                $_ENV['APP_TIMEZONE'] = $previous['env'];
            }
            if ($previous['server'] !== null) {
                $_SERVER['APP_TIMEZONE'] = $previous['server'];
            }
            if ($previous['putenv'] !== false) {
                putenv('APP_TIMEZONE='.$previous['putenv']);
            }
        }
    }

    /**
     * The other half of the pair. The default above is only safe BECAUSE the
     * suite pins its own baseline; drop the pin and every fixed date the suite
     * travels to is silently reinterpreted as Manila time.
     */
    public function test_the_suite_keeps_its_own_utc_baseline(): void
    {
        $this->assertSame(
            'UTC',
            config('app.timezone'),
            'phpunit.xml must keep pinning APP_TIMEZONE=UTC — see config/app.php.'
        );
    }
}
