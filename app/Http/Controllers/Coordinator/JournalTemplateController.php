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
use Illuminate\Support\Facades\DB;

class JournalTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $programIds = $request->user()->coordinatorProgramIds();

        $templates = JournalTemplate::with('programs')
            ->whereHas('programs', fn ($query) => $query->whereIn('programs.id', $programIds))
            ->orderBy('name')
            ->get();

        // Which template (if any) already claims each in-scope program, so the
        // UI can grey out already-covered programs.
        $assignedByProgram = DB::table('journal_template_program')
            ->whereIn('program_id', $programIds)
            ->pluck('journal_template_id', 'program_id');

        $programs = Program::whereIn('id', $programIds)->orderBy('name')->get()
            ->map(fn (Program $program) => [
                'id' => $program->id,
                'code' => $program->code,
                'name' => $program->name,
                'is_active' => $program->is_active,
                'assigned_template_id' => $assignedByProgram[$program->id] ?? null,
            ]);

        return response()->json([
            'templates' => $templates,
            'programs' => $programs,
        ]);
    }

    public function store(StoreJournalTemplateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $programIds = $data['program_ids'];
        unset($data['program_ids']);

        $template = JournalTemplate::create([
            ...$data,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $template->programs()->sync($programIds);

        return response()->json($template->load('programs'), 201);
    }

    public function update(UpdateJournalTemplateRequest $request, JournalTemplate $journalTemplate): JsonResponse
    {
        $removedKeys = array_diff(
            collect($journalTemplate->sections)->pluck('key')->all(),
            collect($request->validated('sections'))->pluck('key')->all(),
        );

        $affectedEntries = $this->countEntriesUsingKeys($journalTemplate, $removedKeys);

        $data = $request->validated();
        $programIds = $data['program_ids'] ?? null;
        unset($data['program_ids']);

        $journalTemplate->update([
            ...$data,
            'is_active' => $request->boolean('is_active', $journalTemplate->is_active),
        ]);

        if ($programIds !== null) {
            $journalTemplate->programs()->sync($programIds);
        }

        return response()->json([
            'template' => $journalTemplate->fresh('programs'),
            'affected_entries' => $affectedEntries,
        ]);
    }

    public function toggleActive(Request $request, JournalTemplate $journalTemplate): JsonResponse
    {
        $programIds = $request->user()->coordinatorProgramIds();

        if (! $journalTemplate->programs()->whereIn('programs.id', $programIds)->exists()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $journalTemplate->update(['is_active' => ! $journalTemplate->is_active]);

        return response()->json($journalTemplate->fresh('programs'));
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
