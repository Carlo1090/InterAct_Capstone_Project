<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 100);
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('logged_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};
