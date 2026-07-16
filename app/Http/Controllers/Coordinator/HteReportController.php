<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\SaveHteReportRequest;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\HteReport;
use App\Models\Program;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HteReportController extends Controller
{
    /**
     * Default signatories per the official HTE & Student Interns List document.
     */
    private const DEFAULT_SIGNATORIES = [
        'signatory_prepared_name' => 'MARIA ANTONNETTE B. GULILAT, MA, LPT',
        'signatory_prepared_title' => 'Practicum Coordinator, CABM-B',
        'signatory_certified_name' => 'MA. ANGELICA B. CALUNSAG, MSA, CPA',
        'signatory_certified_title' => 'CABM Dean',
    ];

    /**
     * Programs (for the optional filter) + academic years available in scope.
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
     * Candidate HTE rows (each in-scope enrolled intern) merged with saved
     * curation, plus signatories (saved or defaults) for an academic year.
     */
    public function show(Request $request, string $academicYear): JsonResponse
    {
        $programId = $this->resolveProgramFilter($request);

        $report = $this->findReport($request->user(), $programId, $academicYear);

        return response()->json(
            $this->reportPayload($request->user(), $academicYear, $programId, $report)
        );
    }

    /**
     * Persist curated report_data (row overrides, manual rows, deletions,
     * signatories, status) for an academic year.
     */
    public function save(SaveHteReportRequest $request, string $academicYear): JsonResponse
    {
        $validated = $request->validated();
        $programId = $validated['program_id'] ?? null;
        $this->authorizeProgramFilter($request->user(), $programId);

        $reportData = [
            'rows' => $validated['rows'] ?? [],
            'manual_rows' => $validated['manual_rows'] ?? [],
            'deleted_ids' => $validated['deleted_ids'] ?? [],
            'signatory_prepared_name' => $validated['signatory_prepared_name'] ?? self::DEFAULT_SIGNATORIES['signatory_prepared_name'],
            'signatory_prepared_title' => $validated['signatory_prepared_title'] ?? self::DEFAULT_SIGNATORIES['signatory_prepared_title'],
            'signatory_certified_name' => $validated['signatory_certified_name'] ?? self::DEFAULT_SIGNATORIES['signatory_certified_name'],
            'signatory_certified_title' => $validated['signatory_certified_title'] ?? self::DEFAULT_SIGNATORIES['signatory_certified_title'],
        ];

        $report = HteReport::updateOrCreate(
            [
                'coordinator_id' => $request->user()->id,
                'program_id' => $programId,
                'academic_year' => $academicYear,
            ],
            [
                'report_data' => $reportData,
                'status' => $validated['status'],
            ]
        );

        return response()->json(
            $this->reportPayload($request->user(), $academicYear, $programId, $report)
        );
    }

    /**
     * Render the included rows to the official-layout blade and download as PDF.
     */
    public function pdf(Request $request, string $academicYear): Response
    {
        $programId = $this->resolveProgramFilter($request);

        $report = $this->findReport($request->user(), $programId, $academicYear);

        $rows = collect($this->buildRows($request->user(), $academicYear, $programId, $report))
            ->filter(fn ($row) => $row['included'])
            ->values();

        $pdf = Pdf::loadView('pdf.hte-report', [
            'academicYear' => $academicYear,
            'rows' => $rows,
            'meta' => $this->reportMeta($report),
        ])->setPaper('a4', 'landscape');

        return $pdf->download("hte-student-interns-list-{$academicYear}.pdf");
    }

    /**
     * @return array<string, mixed>
     */
    private function reportPayload(User $coordinator, string $academicYear, ?int $programId, ?HteReport $report): array
    {
        return [
            'academic_year' => $academicYear,
            'program_id' => $programId,
            'status' => $report?->status ?? 'draft',
            'rows' => $this->buildRows($coordinator, $academicYear, $programId, $report),
            'meta' => $this->reportMeta($report),
        ];
    }

    /**
     * Read + authorize the optional program_id filter from the query string.
     */
    private function resolveProgramFilter(Request $request): ?int
    {
        $programId = $request->query('program_id');
        $programId = blank($programId) ? null : (int) $programId;

        $this->authorizeProgramFilter($request->user(), $programId);

        return $programId;
    }

    private function authorizeProgramFilter(User $coordinator, ?int $programId): void
    {
        if ($programId === null) {
            return;
        }

        abort_unless(
            $coordinator->coordinatorProgramIds()->contains($programId),
            403,
            'You do not have access to this program.'
        );
    }

    private function findReport(User $coordinator, ?int $programId, string $academicYear): ?HteReport
    {
        return HteReport::where('coordinator_id', $coordinator->id)
            ->where('program_id', $programId)
            ->where('academic_year', $academicYear)
            ->first();
    }

    /**
     * Live candidate rows (enrolled interns for the AY within scope), with saved
     * overrides overlaid, tombstoned rows removed, and manual rows appended.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(User $coordinator, string $academicYear, ?int $programId, ?HteReport $report): array
    {
        $programIds = $coordinator->coordinatorProgramIds();

        if ($programId !== null) {
            $programIds = $programIds->intersect([$programId])->values();
        }

        $enrollments = BatchStudent::whereHas('batch', fn ($query) => $query
            ->whereIn('program_id', $programIds)
            ->where('academic_year', $academicYear)
        )
            ->with(['student.studentProfile', 'company', 'batch.program'])
            ->get()
            ->sortBy(fn (BatchStudent $enrollment) => [
                $enrollment->company?->name ?? '',
                $enrollment->student?->name ?? '',
            ])
            ->values();

        $data = $report?->report_data ?? [];
        $deletedIds = collect($data['deleted_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
        $savedRows = collect($data['rows'] ?? [])->keyBy('id');

        $candidateRows = $enrollments
            ->reject(fn (BatchStudent $enrollment) => in_array((int) $enrollment->id, $deletedIds, true))
            ->map(function (BatchStudent $enrollment) use ($savedRows) {
                $row = $this->mapCandidate($enrollment);
                $saved = $savedRows->get($enrollment->id);

                if ($saved) {
                    $row['host_establishment'] = (string) ($saved['host_establishment'] ?? $row['host_establishment']);
                    $row['student_name'] = (string) ($saved['student_name'] ?? $row['student_name']);
                    $row['program'] = (string) ($saved['program'] ?? $row['program']);
                    $row['gender'] = (string) ($saved['gender'] ?? $row['gender']);
                    $row['duration'] = (string) ($saved['duration'] ?? $row['duration']);
                    $row['included'] = (bool) ($saved['included'] ?? true);
                }

                return $row;
            })
            ->values();

        $manualRows = collect($data['manual_rows'] ?? [])->map(fn ($row) => [
            'id' => (string) ($row['id'] ?? uniqid('manual-')),
            'host_establishment' => (string) ($row['host_establishment'] ?? ''),
            'student_name' => (string) ($row['student_name'] ?? ''),
            'program' => (string) ($row['program'] ?? ''),
            'gender' => (string) ($row['gender'] ?? ''),
            'duration' => (string) ($row['duration'] ?? ''),
            'included' => (bool) ($row['included'] ?? true),
            'is_manual' => true,
        ]);

        // A saved row whose source batch_students record no longer exists
        // (e.g. archived, then purged 30+ days later) would otherwise vanish
        // silently, since it's only ever looked up while iterating the live
        // $enrollments query above. Render it from its last-saved snapshot
        // instead of dropping it — is_manual stays false so a future save
        // keeps writing it back into report_data.rows under its original id,
        // exactly like any other non-manual row.
        $liveIds = $enrollments->pluck('id')->map(fn ($id) => (int) $id)->all();
        $orphanedRows = $savedRows
            ->reject(fn ($saved, $id) => in_array((int) $id, $liveIds, true) || in_array((int) $id, $deletedIds, true))
            ->map(fn ($saved, $id) => [
                'id' => (int) $id,
                'host_establishment' => (string) ($saved['host_establishment'] ?? ''),
                'student_name' => (string) ($saved['student_name'] ?? ''),
                'program' => (string) ($saved['program'] ?? ''),
                'gender' => (string) ($saved['gender'] ?? ''),
                'duration' => (string) ($saved['duration'] ?? ''),
                'included' => (bool) ($saved['included'] ?? true),
                'is_manual' => false,
            ])
            ->values();

        return $candidateRows->concat($orphanedRows)->concat($manualRows)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCandidate(BatchStudent $enrollment): array
    {
        return [
            'id' => (int) $enrollment->id,
            'host_establishment' => (string) ($enrollment->company?->name ?? ''),
            'student_name' => $this->formatStudentName($enrollment->student),
            'program' => $this->formatProgram($enrollment),
            'gender' => $this->formatGender($enrollment->student?->studentProfile?->sex),
            'duration' => $this->formatDuration($enrollment->batch),
            'included' => true,
            'is_manual' => false,
        ];
    }

    /**
     * "Last, First M." when the stored name is a clean two-word First Last;
     * otherwise the stored name is kept untouched (PH compound surnames are
     * ambiguous — the coordinator can edit the cell).
     */
    private function formatStudentName(?User $student): string
    {
        $name = trim((string) ($student?->name ?? ''));

        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        if (count($parts) !== 2) {
            return $name;
        }

        [$first, $last] = $parts;
        $middle = trim((string) ($student?->studentProfile?->middle_name ?? ''));
        $initial = $middle !== '' ? ' '.mb_strtoupper(mb_substr($middle, 0, 1)).'.' : '';

        return "{$last}, {$first}{$initial}";
    }

    private function formatProgram(BatchStudent $enrollment): string
    {
        $program = $enrollment->batch?->program;
        $code = (string) ($program?->code ?: ($program?->name ?? ''));
        $yearLevel = (string) ($enrollment->student?->studentProfile?->year_level ?? '');

        if ($code !== '' && preg_match('/\d/', $yearLevel, $matches)) {
            return "{$code}-{$matches[0]}";
        }

        return $code;
    }

    private function formatGender(?string $sex): string
    {
        return blank($sex) ? '' : ucfirst($sex);
    }

    private function formatDuration(?Batch $batch): string
    {
        $start = $batch?->start_date;
        $end = $batch?->end_date;

        if (! $start || ! $end) {
            return '';
        }

        return $start->format('F j, Y').' – '.$end->format('F j, Y');
    }

    /**
     * @return array<string, string>
     */
    private function reportMeta(?HteReport $report): array
    {
        $data = $report?->report_data ?? [];

        return [
            'signatory_prepared_name' => (string) ($data['signatory_prepared_name'] ?? self::DEFAULT_SIGNATORIES['signatory_prepared_name']),
            'signatory_prepared_title' => (string) ($data['signatory_prepared_title'] ?? self::DEFAULT_SIGNATORIES['signatory_prepared_title']),
            'signatory_certified_name' => (string) ($data['signatory_certified_name'] ?? self::DEFAULT_SIGNATORIES['signatory_certified_name']),
            'signatory_certified_title' => (string) ($data['signatory_certified_title'] ?? self::DEFAULT_SIGNATORIES['signatory_certified_title']),
        ];
    }
}
