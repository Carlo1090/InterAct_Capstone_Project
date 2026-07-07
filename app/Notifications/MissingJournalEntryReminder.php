<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MissingJournalEntryReminder extends Notification
{
    use Queueable;

    public function __construct(private readonly string $entryDate)
    {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('[InternTrack] Missing Journal Entry Reminder')
            ->greeting("Hi {$notifiable->name},")
            ->line("You have not yet submitted your daily journal entry for {$this->entryDate}.")
            ->line('Please submit it before the end of the day to keep your OJT records complete.');
    }
}
