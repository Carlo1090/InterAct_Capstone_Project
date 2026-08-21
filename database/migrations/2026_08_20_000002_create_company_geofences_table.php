<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A QR-code-addressable clock-in site, anchored to coordinates the company
 * supervisor captured from their own device while standing at the workplace.
 *
 * A table rather than columns on `companies` for three reasons: a company can
 * host interns at more than one site, the coordinates belong to the QR code
 * rather than to the company record, and `companies` has $timestamps = false
 * plus a #[Fillable] attribute best left undisturbed.
 *
 * `token` is the static payload printed into the QR. `rotation_secret` is the
 * forward door: populate it later and the verify endpoint starts requiring a
 * TOTP `code` alongside the token, with no change to what the student does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_geofences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label', 150);
            $table->string('token', 64)->unique();
            $table->string('rotation_secret', 64)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('radius_meters')->default(150);
            // GeolocationPosition.coords.accuracy at capture time. A fence
            // anchored on a poor fix puts every intern permanently out of
            // range, so this is kept for the coordinator to audit.
            $table->unsignedSmallInteger('captured_accuracy')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_geofences');
    }
};
