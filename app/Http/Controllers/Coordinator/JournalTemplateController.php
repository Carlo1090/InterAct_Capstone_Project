<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\StoreJournalTemplateRequest;
use App\Http\Requests\Coordinator\UpdateJournalTemplateRequest;
use App\Models\Batch;
use App\Models\JournalEntry;
use App\Models\JournalTemplate;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $programIds = $request->user()->coordinatorProgramIds();

        $templates = JournalTemplate::with('program')
            ->whereIn('program_id', $programIds)
            ->orderBy('name')
            ->get();

        $programs = Program::whereIn('id', $programIds)->orderBy('name')->get();

        return response()->json([
            'templates' => $templates,
            'programs' => $programs,
        ]);
    }

    public function store(StoreJournalTemplateRequest $request): JsonResponse
    {
        $template = JournalTemplate::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json($template->load('program'), 201);
    }

    public function update(UpdateJournalTemplateRequest $request, JournalTemplate $journalTemplate): JsonResponse
    {
        $removedKeys = array_diff(
            collect($journalTemplate->sections)->pluck('key')->all(),
            collect($request->validated('sections'))->pluck('key')->all(),
        );

        $affectedEntries = $this->countEntriesUsingKeys($journalTemplate, $removedKeys);

        $journalTemplate->update([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', $journalTemplate->is_active),
        ]);

        return response()->json([
            'template' => $journalTemplate->fresh('program'),
            'affected_entries' => $affectedEntries,
        ]);
    }

    public function toggleActive(Request $request, JournalTemplate $journalTemplate): JsonResponse
    {
        $programIds = $request->user()->coordinatorProgramIds();

        if (! $programIds->contains($journalTemplate->program_id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $journalTemplate->update(['is_active' => ! $journalTemplate->is_active]);

        return response()->json($journalTemplate->fresh('program'));
    }

    /**
     * @param  array<int, string>  $removedKeys
     */
    private function countEntriesUsingKeys(JournalTemplate $journalTemplate, array $removedKeys): int
    {
        if (empty($removedKeys)) {
            return 0;
        }

        $batchIds = Batch::where('journal_template_id', $journalTemplate->id)->pluck('id');

        return JournalEntry::whereIn('batch_id', $batchIds)
            ->get(['content'])
            ->filter(fn (JournalEntry $entry) => collect($removedKeys)->contains(
                fn ($key) => trim((string) ($entry->content[$key] ?? '')) !== ''
            ))
            ->count();
    }
}
