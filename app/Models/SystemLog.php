<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'action', 'description', 'ip_address', 'logged_at'])]
class SystemLog extends Model
{
    public $timestamps = false;

    const CREATED_AT = 'logged_at';

    const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, ?string $description = null): self
    {
        return static::create([
            'user_id' => request()->user()?->id,
            'action' => $action,
            'description' => $description,
            'ip_address' => request()->ip(),
            // Not the column's useCurrent() default — that stamps using the
            // DB server's own clock/timezone (MySQL's local dev default is
            // `time_zone=SYSTEM`, i.e. the machine's local time), while every
            // read of this column goes through Carbon in app.timezone (UTC).
            // A mismatched server timezone made every "X ago" read as "X from
            // now". Explicit now() keeps this column consistent with the app's
            // own clock regardless of what the DB server's timezone is set to.
            'logged_at' => now(),
        ]);
    }
}
