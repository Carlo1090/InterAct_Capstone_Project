<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * The admin's System Settings page collects a "System Email" that every
 * outbound Notification should send from when it's set (falling back to
 * MAIL_FROM_ADDRESS otherwise). Shared by MissingJournalEntryReminder and
 * NewAccountCredentials so the two can never resolve this differently.
 */
class SystemMailFrom
{
    public static function resolve(): ?string
    {
        $value = trim((string) SystemSetting::cached()->get('system_email'));

        // Stored free-form and validated only on write, so re-check here
        // rather than handing a malformed address to the transport.
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }
}
