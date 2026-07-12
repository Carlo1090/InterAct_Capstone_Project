<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move template<->program from a 1:1 column (journal_templates.program_id) to a
 * many-to-many pivot with the invariant that a program belongs to AT MOST ONE
 * template (UNIQUE(program_id)). Backfill existing rows, then drop the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_template_program', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_template_id')->constrained('journal_templates')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            // A program can be covered by at most one template, ever.
            $table->unique('program_id');
        });

        // Backfill one pivot row per existing template from its current program.
        foreach (DB::table('journal_templates')->select('id', 'program_id')->get() as $template) {
            if ($template->program_id === null) {
                continue;
            }

            DB::table('journal_template_program')->insert([
                'journal_template_id' => $template->id,
                'program_id' => $template->program_id,
            ]);
        }

        // The pivot is now the single source of truth — drop the old column + FK.
        Schema::table('journal_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_templates', function (Blueprint $table) {
            $table->foreignId('program_id')->nullable()->after('id')->constrained('programs')->cascadeOnDelete();
        });

        // Restore a single program per template (the first claimed one).
        foreach (DB::table('journal_template_program')->orderBy('id')->get() as $row) {
            DB::table('journal_templates')
                ->where('id', $row->journal_template_id)
                ->whereNull('program_id')
                ->update(['program_id' => $row->program_id]);
        }

        Schema::dropIfExists('journal_template_program');
    }
};
