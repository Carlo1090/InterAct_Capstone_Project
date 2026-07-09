<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\SaveAnnualSippReportRequest;
use App\Models\Batch;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\SippAnnualReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AnnualSippReportController extends Controller
{
    /**
     * Default signatories per the official OJT Annual SIPP Report document.
     */
    private const DEFAULT_SIGNATORIES = [
        'signatory_prepared_name' => 'MARIA ANTONNETTE B. GULILAT, MA, LPT',
        'signatory_prepared_title' => 'Practicum Coordinator, CABM-B',
        'signatory_certified_name' => 'MA. ANGELICA B. CALUNSAG, MSA, CPA',
        'signatory_certified_title' => 'CABM Dean',
    ];

    /**
     * Program tabs + academic years available in the coordinator's scope.
     */
    public function index(Request $request): JsonResponse
    {
        $programIds = $request->user()->coordinatorProgramIds();

        $programs = Program::whereIn('id', $programIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $academicYears = Batch::whereIn('program_id', $programIds)
            ->whereNotNull('academic_year')
            ->distinct()
            ->orderByDesc('academic_year')
            ->pluck('academic_year')
            ->values();

        return response()->json([
            'programs' => $programs,
            'academic_years' => $academicYears,
        ]);
    }

    /**
     * Candidate rows (each qualifying journal entry) merged with saved curation,
     * plus signatories + heading (saved or defaults) for a program + academic year.
     */
    public function show(Request $request, Program $program): JsonResponse
    {
        $this->authorizeProgram($request, $program);

        $academicYear = $this->requireAcademicYear($request);

        $report = $this->findReport($request, $program, $academicYear);

        return response()->json([
            'program' => $program->only(['id', 'name', 'code']),
            'academic_year' => $academicYear,
            'status' => $report?->status ?? 'draft',
            'rows' => $this->buildRows($program, $academicYear, $report),
            'meta' => $this->reportMeta($program, $report),
        ]);
    }

    /**
     * Persist curated report_data (rows, tombstones, signatories, heading, status).
     */
    public function save(SaveAnnualSippReportRequest $request, Program $program): JsonResponse
    {
        $this->authorizeProgram($request, $program);

        $validated = $request->validated();
        $academicYear = $validated['academic_year'];

        $reportData = [
            'rows' => $validated['rows'] ?? [],
            'deleted_ids' => $validated['deleted_ids'] ?? [],
            'heading' => $validated['heading'],
            'status' => $validated['status'],
            'signatory_prepared_name' => $validated['signatory_prepared_name'] ?? self::DEFAULT_SIGNATORIES['signatory_prepared_name'],
            'signatory_prepared_title' => $validated['signatory_prepared_title'] ?? self::DEFAULT_SIGNATORIES['signatory_prepared_title'],
            'signatory_certified_name' => $validated['signatory_certified_name'] ?? self::DEFAULT_SIGNATORIES['signatory_certified_name'],
            'signatory_certified_title' => $validated['signatory_certified_title'] ?? self::DEFAULT_SIGNATORIES['signatory_certified_title'],
        ];

        $report = SippAnnualReport::updateOrCreate(
            [
                'coordinator_id' => $request->user()->id,
                'program_id' => $program->id,
                'academic_year' => $academicYear,
            ],
            [
                'report_data' => $reportData,
                'status' => $validated['status'],
            ]
        );

        return response()->json([
            'program' => $program->only(['id', 'name', 'code']),
            'academic_year' => $academicYear,
            'status' => $report->status,
            'rows' => $this->buildRows($program, $academicYear, $report),
            'meta' => $this->reportMeta($program, $report),
        ]);
    }

    /**
     * Render the included rows to the official-layout blade and download as PDF.
     */
    public function pdf(Request $request, Program $program): Response
    {
        $this->authorizeProgram($request, $program);

        $academicYear = $this->requireAcademicYear($request);

        $report = $this->findReport($request, $program, $academicYear);

        $rows = collect($this->buildRows($program, $academicYear, $report))
            ->filter(fn ($row) => $row['included'])
            ->values();

        $pdf = Pdf::loadView('pdf.annual-sipp-report', [
            'academicYear' => $academicYear,
            'rows' => $rows,
            'meta' => $this->reportMeta($program, $report),
        ]);

        $slug = $program->code ?: $program->id;

        return $pdf->download("annual-sipp-report-{$slug}-{$academicYear}.pdf");
    }

    private function authorizeProgram(Request $request, Program $program): void
    {
        abort_unless(
            $request->user()->coordinatorProgramIds()->contains($program->id),
            403,
            'You do not have access to this program.'
        );
    }

    private function requireAcademicYear(Request $request): string
    {
        $academicYear = $request->query('academic_year');

        abort_if(blank($academicYear), 422, 'An academic year is required.');

        return (string) $academicYear;
    }

    private function findReport(Request $request, Program $program, string $academicYear): ?SippAnnualReport
    {
        return SippAnnualReport::where('coordinator_id', $request->user()->id)
            ->where('program_id', $program->id)
            ->where('academic_year', $academicYear)
            ->first();
    }

    /**
     * Live candidate rows (journal entries with SIPP content for program + AY),
     * with saved edits/inclusion overlaid and tombstoned rows removed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(Program $program, string $academicYear, ?SippAnnualReport $report): array
    {
        $entries = JournalEntry::whereHas('batch', fn ($query) => $query
            ->where('program_id', $program->id)
            ->where('academic_year', $academicYear)
        )
            ->with('student:id,name')
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $data = $report?->report_data ?? [];
        $deletedIds = collect($data['deleted_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
        $savedRows = collect($data['rows'] ?? [])->keyBy('id');

        return $entries
            ->filter(fn (JournalEntry $entry) => $this->hasSippContent($entry->content ?? []))
            ->reject(fn (JournalEntry $entry) => in_array((int) $entry->id, $deletedIds, true))
            ->map(function (JournalEntry $entry) use ($savedRows) {
                $content = $entry->content ?? [];

                $row = [
                    'id' => (int) $entry->id,
                    'student_name' => $entry->student?->name ?? '',
                    'entry_date' => $entry->entry_date->toDateString(),
                    'issues_concerns' => (string) ($content['issues_concerns'] ?? ''),
                    'solutions' => (string) ($content['solutions'] ?? ''),
                    'recommendations' => (string) ($content['recommendations'] ?? ''),
                    'included' => true,
                ];

                $saved = $savedRows->get($entry->id);

                if ($saved) {
                    $row['issues_concerns'] = (string) ($saved['issues_concerns'] ?? $row['issues_concerns']);
                    $row['solutions'] = (string) ($saved['solutions'] ?? $row['solutions']);
                    $row['recommendations'] = (string) ($saved['recommendations'] ?? $row['recommendations']);
                    $row['included'] = (bool) ($saved['included'] ?? true);
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function hasSippContent(array $content): bool
    {
        foreach (['issues_concerns', 'solutions', 'recommendations'] as $key) {
            if (trim((string) ($content[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function reportMeta(Program $program, ?SippAnnualReport $report): array
    {
        $data = $report?->report_data ?? [];

        return [
            'heading' => (string) ($data['heading'] ?? $program->name),
            'signatory_prepared_name' => (string) ($data['signatory_prepared_name'] ?? self::DEFAULT_SIGNATORIES['signatory_prepared_name']),
            'signatory_prepared_title' => (string) ($data['signatory_prepared_title'] ?? self::DEFAULT_SIGNATORIES['signatory_prepared_title']),
            'signatory_certified_name' => (string) ($data['signatory_certified_name'] ?? self::DEFAULT_SIGNATORIES['signatory_certified_name']),
            'signatory_certified_title' => (string) ($data['signatory_certified_title'] ?? self::DEFAULT_SIGNATORIES['signatory_certified_title']),
        ];
    }
}
