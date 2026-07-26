<?php

namespace App\Notifications;

use App\Models\SystemSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MissingJournalEntryReminder extends Notification
{
    use Queueable;

    public function __construct(private readonly string $entryDate) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('[InternTrack] Missing Journal Entry Reminder')
            ->greeting("Hi {$notifiable->name},")
            ->line("You have not yet submitted your daily journal entry for {$this->entryDate}.")
            ->line('Please submit it before the end of the day to keep your OJT records complete.');

        // The admin's System Settings page has always collected a "System Email"
        // and nothing has ever read it. Use it as the from address when set, so
        // reminders come from the institution rather than MAIL_FROM_ADDRESS.
        $systemEmail = $this->systemEmail();

        if ($systemEmail !== null) {
            $message->from($systemEmail, config('app.name'));
        }

        return $message;
    }

    private function systemEmail(): ?string
    {
        $value = trim((string) SystemSetting::where('key', 'system_email')->value('value'));

        // Stored free-form and validated only on write, so re-check here rather
        // than handing a malformed address to the transport.
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }
}
