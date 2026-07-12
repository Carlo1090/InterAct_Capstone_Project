<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fixed, structural section every journal template must carry — the
     * guaranteed source Weekly Bundling compiles from. Prepended to any
     * existing template's sections that doesn't already have this key.
     */
    private const FIXED_SECTION = [
        'key' => 'daily_accomplishment',
        'label' => 'Daily Accomplishment',
        'prompt' => 'Summarize what you accomplished today.',
        'required' => true,
        'sipp' => false,
    ];

    public function up(): void
    {
        DB::table('journal_templates')->select(['id', 'sections'])->orderBy('id')->each(function ($template) {
            $sections = json_decode($template->sections, true) ?? [];

            $hasFixedSection = collect($sections)->contains(fn ($section) => ($section['key'] ?? null) === self::FIXED_SECTION['key']);

            if ($hasFixedSection) {
                return;
            }

            DB::table('journal_templates')
                ->where('id', $template->id)
                ->update(['sections' => json_encode([self::FIXED_SECTION, ...$sections])]);
        });
    }

    public function down(): void
    {
        DB::table('journal_templates')->select(['id', 'sections'])->orderBy('id')->each(function ($template) {
            $sections = json_decode($template->sections, true) ?? [];

            $withoutFixed = collect($sections)
                ->reject(fn ($section) => ($section['key'] ?? null) === self::FIXED_SECTION['key'])
                ->values()
                ->all();

            DB::table('journal_templates')
                ->where('id', $template->id)
                ->update(['sections' => json_encode($withoutFixed)]);
        });
    }
};
