<?php

namespace App\Support;

/**
 * Whether the configured mailer delivers to a real inbox at all.
 *
 * The `log` transport writes each message into storage/logs and sends
 * nothing, and render.yaml ships MAIL_MAILER=log. `notify()` never throws
 * under it, so every caller that reported "emailed" on a clean return told
 * the coordinator a student had their password when nobody could ever
 * receive it. Callers check this first and report "not emailed" instead,
 * which is what puts the password-handover UI in front of them.
 *
 * `array` is deliberately treated as live: it is the test suite's mailer
 * (phpunit.xml), and the tests exercise the delivered path.
 */
class MailDelivery
{
    public static function isLive(): bool
    {
        $mailer = (string) config('mail.default');

        return config("mail.mailers.{$mailer}.transport", $mailer) !== 'log';
    }
}
