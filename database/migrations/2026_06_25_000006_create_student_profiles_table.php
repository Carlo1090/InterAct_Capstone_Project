<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('student_id_number', 30)->unique();
            $table->string('middle_name', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('sex', ['male', 'female'])->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->text('home_address')->nullable();
            $table->string('year_level', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
