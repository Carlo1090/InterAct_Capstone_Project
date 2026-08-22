<?php

namespace App\Console\Commands;

use App\Notifications\NewAccountCredentials;
use App\Support\SystemMailFrom;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Verify outbound mail actually works, BEFORE relying on it for a real
 * roster import.
 *
 * Without this, the only way to discover that SMTP credentials are dead is to
 * bulk-import 40 students and watch all 40 come back `created_email_failed` —
 * every one of them then needing an individual Resend. This sends exactly one
 * message and reports precisely why it failed.
 *
 *   php artisan mail:test you@example.com
 *
 * Tip: with Gmail you can send to a plus-addressed variant of your own inbox
 * (you+test@gmail.com) — a different recipient string that still lands in your
 * own mail, which proves arbitrary-recipient delivery without emailing anyone
 * else.
 */
class TestMailConfiguration extends Command
{
    protected $signature = 'mail:test
                            {address : Where to send the test message.}
                            {--raw : Show the full exception instead of the interpreted diagnosis.}';

    protected $description = 'Send one real test email and report exactly why it succeeded or failed.';

    public function handle(): int
    {
        $address = (string) $this->argument('address');

        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $this->error("\"{$address}\" is not a valid email address.");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');

        $this->line('Transport:  '.$mailer);

        if ($mailer === 'smtp') {
            $this->line('SMTP host:  '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
            $this->line('SMTP user:  '.config('mail.mailers.smtp.username'));
        }

        // SystemMailFrom::resolve() reads the DB but is null-safe on failure,
        // so this diagnostic still runs (and still sends) when the database is
        // down — that is a separate problem from the mail answer being sought.
        $systemEmail = SystemMailFrom::resolve();
        $this->line('From:       '.($systemEmail ?? config('mail.from.address')).($systemEmail ? '  (System Settings)' : '  (MAIL_FROM_ADDRESS)'));
        $this->line('Login link: '.rtrim((string) config('app.frontend_url'), '/').'/login');
        $this->line('Sending to: '.$address);
        $this->newLine();

        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER=log — this writes to storage/logs/laravel.log and sends NOTHING.');
            $this->warn('Set MAIL_MAILER=smtp in .env to send for real.');
        }

        try {
            $start = microtime(true);

            // Deliberately sends the REAL welcome notification rather than a
            // throwaway message, so this exercises the exact template, from
            // address and login link a bulk-imported student receives.
            Notification::route('mail', $address)
                ->notify(new NewAccountCredentials('TEST-ID-NUMBER', 'not-a-real-password'));

            $ms = round((microtime(true) - $start) * 1000);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('FAILED to send.');
            $this->newLine();

            if ($this->option('raw')) {
                $this->line($e->getMessage());
            } else {
                $this->diagnose($e);
            }

            return self::FAILURE;
        }

        $this->info("Sent successfully in {$ms}ms.");

        if ($mailer === 'log') {
            $this->warn('...to the LOG file, not to a real inbox. Check storage/logs/laravel.log.');
        } else {
            $this->line('Check the inbox (and the spam folder — a personal Gmail sender often lands there).');
        }

        return self::SUCCESS;
    }

    /**
     * Turn Symfony Mailer's wall of authenticator output into the one thing
     * the operator actually has to go and do.
     */
    private function diagnose(Throwable $e): void
    {
        $message = $e->getMessage();

        $is = fn (string $needle) => str_contains($message, $needle);

        match (true) {
            $is('535') || $is('BadCredentials') || $is('Username and Password not accepted') => $this->explain(
                'Google rejected the username/password (SMTP 535).',
                [
                    'The MAIL_PASSWORD in .env is a Google App Password that is no longer valid.',
                    'App passwords are invalidated when 2-Step Verification is turned off, or when',
                    'the account password is changed, or if the app password was revoked.',
                    '',
                    'Fix: generate a NEW app password at https://myaccount.google.com/apppasswords',
                    '(2-Step Verification must be ON), then paste the 16 characters into',
                    'MAIL_PASSWORD with no spaces and no quotes, and re-run this command.',
                ]
            ),
            $is('Connection could not be established') || $is('Connection timed out') || $is('getaddrinfo') => $this->explain(
                'Could not reach the SMTP server at all.',
                [
                    'Check MAIL_HOST/MAIL_PORT, and whether a firewall or the network is',
                    'blocking outbound port '.config('mail.mailers.smtp.port').'.',
                ]
            ),
            $is('SSL') || $is('certificate') || $is('cURL error 60') => $this->explain(
                'TLS/certificate failure.',
                [
                    'A Windows PHP build ships no CA bundle. Download https://curl.se/ca/cacert.pem',
                    'and point BOTH curl.cainfo and openssl.cafile at it in php.ini.',
                    'Note a WinGet PHP upgrade silently wipes those lines — re-apply them.',
                ]
            ),
            $is('Expected response code "250"') && $is('scheme') => $this->explain(
                'The transport rejected the connection scheme.',
                ['MAIL_SCHEME must be null (not "tls") on port 587 — 587 does STARTTLS on its own.']
            ),
            default => $this->explain('Unrecognised mail error.', [trim(explode("\n", $message)[0])]),
        };

        $this->newLine();
        $this->line('Re-run with --raw to see the full exception.');
    }

    /**
     * @param  list<string>  $lines
     */
    private function explain(string $headline, array $lines): void
    {
        $this->warn($headline);
        $this->newLine();

        foreach ($lines as $line) {
            $this->line('  '.$line);
        }
    }
}
