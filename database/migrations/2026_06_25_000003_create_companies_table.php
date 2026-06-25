<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->text('address');
            $table->string('location', 200)->nullable();
            $table->string('industry', 100)->nullable();
            $table->string('contact_number', 30)->nullable();
            $table->string('head_name', 150)->nullable();
            $table->string('department_head', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
