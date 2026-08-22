<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'created_by', 'label', 'latitude', 'longitude', 'radius_meters', 'captured_accuracy', 'is_active'])]
class CompanyGeofence extends Model
{
    /**
     * Accuracy (in metres) beyond which a capture is too vague to anchor a
     * fence on. Advisory — the supervisor is warned, not blocked, because a
     * hard block would strand a company whose building simply has bad GPS.
     */
    public const POOR_ACCURACY_METRES = 100;

    /**
     * token and rotation_secret are deliberately NOT fillable — they are
     * generated here, never accepted from a request.
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_meters' => 'integer',
            'captured_accuracy' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CompanyGeofence $geofence) {
            $geofence->token ??= Str::random(32);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(DtrSession::class, 'geofence_id');
    }

    /**
     * True once a rotation secret has been issued for this site. Nothing sets
     * one today; the verify path reads this so switching rotation on later is
     * a data change rather than a code change.
     */
    public function requiresRotatingCode(): bool
    {
        return $this->rotation_secret !== null;
    }
}
