<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value'])]
class SystemSetting extends Model
{
    public $timestamps = false;

    const UPDATED_AT = 'updated_at';

    const CREATED_AT = null;

    /**
     * All settings as a key => value map. Cached since this tiny table is
     * read on every admin Settings page load AND on every reminder-email
     * built (MissingJournalEntryReminder), potentially once per student per
     * reminder run. Call forgetCache() wherever a setting is written.
     */
    public static function cached(): Collection
    {
        // Cache a plain array, never the Collection itself — see the note on
        // User::coordinatorProgramIds(). `serializable_classes => false` means an
        // object read back from the database cache store returns as
        // __PHP_Incomplete_Class, which would break this `: Collection` return
        // type on every request after the first. Key => value stays intact
        // through the array round trip.
        $settings = Cache::remember(
            'reference:system-settings',
            now()->addDay(),
            fn () => static::query()->pluck('value', 'key')->all(),
        );

        return collect($settings);
    }

    public static function forgetCache(): void
    {
        Cache::forget('reference:system-settings');
    }
}
