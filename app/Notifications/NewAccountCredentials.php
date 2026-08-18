<?php

namespace App\Notifications;

use App\Support\SystemMailFrom;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The welcome email for a coordinator-provisioned account (bulk Excel import,
 * or a manual "Resend Credentials" reissue) — username + temporary password
 * + a link to sign in. This is a deliberate, scoped exception to the "only
 * mail a Google-verified address" rule the rest of the app follows
 * (MissingJournalEntryReminder): the coordinator trusts the roster data
 * directly, since there is no verified address to wait for at account
 * creation time. The recipient still must change this password on first
 * login (users.must_change_password).
 */
class NewAccountCredentials extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $username,
        private readonly string $temporaryPassword,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $loginUrl = rtrim((string) config('app.frontend_url'), '/').'/login';

        $message = (new MailMessage)
            ->subject('[InternTrack] Your account is ready')
            ->greeting("Hi {$notifiable->name},")
            ->line('An InternTrack account has been created for you. Use the credentials below to sign in.')
            ->line("Username: {$this->username}")
            ->line("Temporary password: {$this->temporaryPassword}")
            ->action('Sign in to InternTrack', $loginUrl)
            ->line('You will be asked to choose a new password the first time you sign in.')
            ->line("If you weren't expecting this, contact your OJT coordinator.");

        $systemEmail = SystemMailFrom::resolve();

        if ($systemEmail !== null) {
            $message->from($systemEmail, config('app.name'));
        }

        return $message;
    }
}
