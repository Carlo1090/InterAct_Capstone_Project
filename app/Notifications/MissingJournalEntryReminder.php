<?php

namespace App\Notifications;

use App\Models\SystemSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * One reminder per trigger covering EVERY missing day of the current week, not
 * a separate mail per day — the command dedupes on (user, title, today), so a
 * student receives this at most once in a day and it must therefore carry the
 * whole backlog rather than just today's gap.
 */
class MissingJournalEntryReminder extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $missingDates  Y-m-d, ascending, Monday..today. May or
     *                                      may not include $today: a student who
     *                                      submitted today can still owe earlier days.
     * @param  string  $today  Y-m-d
     */
    public function __construct(
        private readonly array $missingDates,
        private readonly string $today,
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
        $dates = array_values($this->missingDates);
        $todayMissing = in_array($this->today, $dates, true);
        $backlog = array_values(array_filter($dates, fn (string $date) => $date !== $this->today));
        $count = count($dates);

        $message = (new MailMessage)
            ->subject($this->subjectFor($count, $todayMissing))
            ->greeting("Hi {$notifiable->name},");

        if ($todayMissing) {
            $message->line('You have not submitted your daily journal entry for today, '.$this->longDate($this->today).'.');

            if ($backlog !== []) {
                $message->line(count($backlog) === 1
                    ? 'One earlier day this week is also still missing:'
                    : count($backlog).' earlier days this week are also still missing:');
            }
        } else {
            // Reached only when today is already submitted (or was never a
            // working day) but earlier days are still blank.
            $message->line(count($backlog) === 1
                ? 'You are up to date for today, but one earlier day this week is still missing:'
                : 'You are up to date for today, but '.count($backlog).' earlier days this week are still missing:');
        }

        foreach ($backlog as $date) {
            // A literal bullet rather than a markdown list: each ->line() is
            // rendered as its own paragraph, so "- x" would become a one-item
            // list per day instead of a single grouped list.
            $message->line('• '.$this->longDate($date));
        }

        $message
            ->line('Please write '.($count === 1 ? 'it' : 'them').' up in InternTrack so your OJT records stay complete.')
            ->action('Write my journal', rtrim((string) config('app.frontend_url'), '/').'/student/write-journal')
            ->line('You are receiving this because your email is verified and reminders are switched on. You can change your reminder days and time, or turn reminders off, under Reminder Settings in your profile menu.');

        // The admin's System Settings page has always collected a "System Email"
        // and nothing has ever read it. Use it as the from address when set, so
        // reminders come from the institution rather than MAIL_FROM_ADDRESS.
        $systemEmail = $this->systemEmail();

        if ($systemEmail !== null) {
            $message->from($systemEmail, config('app.name'));
        }

        return $message;
    }

    /**
     * The in-app bell row's message, kept here so the notification and the
     * command can never describe the same trigger differently.
     *
     * @param  list<string>  $missingDates
     */
    public static function inAppMessage(array $missingDates, string $today): string
    {
        $dates = array_values($missingDates);

        if (count($dates) === 1) {
            return 'You have not submitted your daily journal entry for '.Carbon::parse($dates[0])->format('M j, Y').'.';
        }

        $list = implode(', ', array_map(
            fn (string $date) => Carbon::parse($date)->format('M j'),
            $dates
        ));

        return 'You have '.count($dates).' missing journal entries this week: '.$list.'.';
    }

    private function subjectFor(int $count, bool $todayMissing): string
    {
        if ($count === 1 && $todayMissing) {
            return '[InternTrack] Missing journal entry for today';
        }

        return $count === 1
            ? '[InternTrack] You have 1 missing journal entry this week'
            : "[InternTrack] You have {$count} missing journal entries this week";
    }

    private function longDate(string $date): string
    {
        return Carbon::parse($date)->format('l, F j, Y');
    }

    private function systemEmail(): ?string
    {
        $value = trim((string) SystemSetting::cached()->get('system_email'));

        // Stored free-form and validated only on write, so re-check here rather
        // than handing a malformed address to the transport.
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }
}
