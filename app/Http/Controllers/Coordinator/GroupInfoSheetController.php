<?php

namespace App\Http\Controllers\Coordinator;

use App\Http\Controllers\Concerns\BuildsGroupInfoSheetPdf;
use App\Http\Controllers\Controller;
use App\Http\Requests\Coordinator\SaveGroupInfoSheetRequest;
use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\GroupInfoSheet;
use App\Models\StudentInformationSheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The GROUP Student Information Sheet (docs/"Student Information Sheet (Group)").
 *
 * One document per COMPANY per academic year: every in-scope intern placed at
 * that company is rostered in the STUDENT TRAINEE INFORMATION table, above a
 * single INTERNSHIP COMPANY INFORMATION block that the coordinator types
 * themselves. The company block is deliberately NOT sourced from any one
 * student's information sheet — a dozen interns at the same company each type
 * their own version of the address/signatory/supervisor, and those disagree.
 *
 * Structured on HteReportController: live candidate rows overlaid with a saved
 * curation layer (overrides, manual rows, tombstones) that never mutates the
 * source enrollments or information sheets.
 */
class GroupInfoSheetController extends Controller
{
    use BuildsGroupInfoSheetPdf;

    /**
     * Enrollment statuses that belong on the sheet. A `completed` intern was
     * genuinely hosted by the company; a `dropped` one never was.
     */
    private const ROSTERED_STATUSES = ['active', 'completed'];

    /**
     * The document's header line, as printed on the client reference form.
     * Coordinator-editable per sheet — this is only the starting value.
     */
    private const DEFAULT_DEPARTMENT_LINE = 'College of Accountancy, Business and Management';

    /**
     * Academic years in scope + the companies that actually host in-scope
     * interns in each. A company with no roster would render an empty sheet,
     * so it is never offered.
     */
    public function index(Request $request): JsonResponse
    {
        $coordinator = $request->user();
        $programIds = $coordinator->coordinatorProgramIds();

        $academicYears = Batch::whereIn('program_id', $programIds)
            ->whereNotNull('academic_year')
            ->distinct()
            ->orderByDesc('academic_year')
            ->pluck('academic_year')
            ->values();

        $companies = $this->rosterQuery($programIds)
            ->with(['company:id,name', 'batch:id,academic_year'])
            ->get()
            ->groupBy('company_id')
            ->map(fn (EloquentCollection $rows) => [
                'id' => (int) $rows->first()->company_id,
                'name' => (string) ($rows->first()->company?->name ?? ''),
                'academic_years' => $rows
                    ->pluck('batch.academic_year')
                    ->filter()
                    ->unique()
                    ->sortDesc()
                    ->values()
                    ->all(),
            ])
            ->sortBy('name')
            ->values();

        return response()->json([
            'academic_years' => $academicYears,
            'companies' => $companies,
        ]);
    }

    /**
     * Live roster rows merged with saved curation, plus the coordinator-typed
     * company block and editable department header line.
     */
    public function show(Request $request, Company $company, string $academicYear): JsonResponse
    {
        $coordinator = $request->user();
        $enrollments = $this->assertCompanyInScope($coordinator, $company, $academicYear);

        $sheet = $this->findSheet($coordinator, $company, $academicYear);

        return response()->json(
            $this->sheetPayload($company, $academicYear, $enrollments, $sheet)
        );
    }

    /**
     * Persist the curated sheet_data (header line, company block, row
     * overrides, manual rows, tombstones, status).
     */
    public function save(SaveGroupInfoSheetRequest $request, Company $company, string $academicYear): JsonResponse
    {
        $coordinator = $request->user();
        $enrollments = $this->assertCompanyInScope($coordinator, $company, $academicYear);

        $validated = $request->validated();

        $sheetData = [
            'header' => [
                'department_line' => $validated['department_line'] ?? self::DEFAULT_DEPARTMENT_LINE,
            ],
            'company' => $validated['company'] ?? [],
            'rows' => $validated['rows'] ?? [],
            'manual_rows' => $validated['manual_rows'] ?? [],
            'deleted_ids' => $validated['deleted_ids'] ?? [],
        ];

        $sheet = GroupInfoSheet::updateOrCreate(
            [
                'coordinator_id' => $coordinator->id,
                'company_id' => $company->id,
                'academic_year' => $academicYear,
            ],
            [
                'sheet_data' => $sheetData,
                'status' => $validated['status'],
            ]
        );

        return response()->json(
            $this->sheetPayload($company, $academicYear, $enrollments, $sheet)
        );
    }

    /**
     * Render the included rows + company block to the official-layout blade.
     */
    public function pdf(Request $request, Company $company, string $academicYear): Response
    {
        $coordinator = $request->user();
        $enrollments = $this->assertCompanyInScope($coordinator, $company, $academicYear);

        $sheet = $this->findSheet($coordinator, $company, $academicYear);

        $rows = collect($this->buildRows($enrollments, $sheet))
            ->filter(fn (array $row) => $row['included'])
            ->values()
            ->all();

        return $this->renderGroupInfoSheetPdf(
            $company,
            $academicYear,
            $this->departmentLine($sheet),
            $rows,
            $this->companyBlock($company, $enrollments, $sheet),
        );
    }

    /**
     * @param  EloquentCollection<int, BatchStudent>  $enrollments
     * @return array<string, mixed>
     */
    private function sheetPayload(
        Company $company,
        string $academicYear,
        EloquentCollection $enrollments,
        ?GroupInfoSheet $sheet,
    ): array {
        return [
            'academic_year' => $academicYear,
            'company_id' => (int) $company->id,
            'company_name' => (string) $company->name,
            'status' => $sheet?->status ?? 'draft',
            'department_line' => $this->departmentLine($sheet),
            'company' => $this->companyBlock($company, $enrollments, $sheet),
            'rows' => $this->buildRows($enrollments, $sheet),
        ];
    }

    /**
     * The base roster query: in-scope, rostered (never dropped), never archived.
     *
     * @param  Collection<int, int>  $programIds
     */
    private function rosterQuery(Collection $programIds, ?string $academicYear = null)
    {
        return BatchStudent::whereIn('status', self::ROSTERED_STATUSES)
            ->whereNull('archived_at')
            ->whereHas('batch', function ($query) use ($programIds, $academicYear) {
                $query->whereIn('program_id', $programIds);

                if ($academicYear !== null) {
                    $query->where('academic_year', $academicYear);
                }
            });
    }

    /**
     * A company is in scope only when it actually hosts at least one in-scope
     * intern for that academic year — which is also the roster itself, so it
     * is returned rather than recomputed by every caller.
     *
     * @return EloquentCollection<int, BatchStudent>
     */
    private function assertCompanyInScope(User $coordinator, Company $company, string $academicYear): EloquentCollection
    {
        $enrollments = $this->rosterQuery($coordinator->coordinatorProgramIds(), $academicYear)
            ->where('company_id', $company->id)
            ->with(['student.studentProfile', 'batch.program'])
            ->get();

        abort_if(
            $enrollments->isEmpty(),
            403,
            'This company has no interns from your program(s) for that academic year.'
        );

        return $enrollments->sortBy(fn (BatchStudent $enrollment) => mb_strtolower(
            (string) ($enrollment->student?->name ?? '')
        ))->values();
    }

    private function findSheet(User $coordinator, Company $company, string $academicYear): ?GroupInfoSheet
    {
        return GroupInfoSheet::where('coordinator_id', $coordinator->id)
            ->where('company_id', $company->id)
            ->where('academic_year', $academicYear)
            ->first();
    }

    /**
     * Live roster rows with saved overrides overlaid, tombstoned rows removed,
     * orphaned saved rows preserved, and manual rows appended.
     *
     * @param  EloquentCollection<int, BatchStudent>  $enrollments
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(EloquentCollection $enrollments, ?GroupInfoSheet $sheet): array
    {
        $data = $sheet?->sheet_data ?? [];
        $deletedIds = collect($data['deleted_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
        $savedRows = collect($data['rows'] ?? [])->keyBy('id');

        $infoSheets = $this->infoSheetsFor($enrollments);

        $candidateRows = $enrollments
            ->reject(fn (BatchStudent $enrollment) => in_array((int) $enrollment->id, $deletedIds, true))
            ->map(function (BatchStudent $enrollment) use ($savedRows, $infoSheets) {
                $row = $this->mapCandidate($enrollment, $infoSheets->get($enrollment->student_id));
                $saved = $savedRows->get($enrollment->id);

                if ($saved) {
                    foreach (self::rowFields() as $field) {
                        // Only a NON-EMPTY saved edit overrides the student's own
                        // information sheet. An empty override is treated as "no
                        // edit", not as "blank this cell" — otherwise saving the
                        // sheet once while a student had not yet filled in, say,
                        // their guardian's name would freeze that blank forever
                        // and permanently mask what they typed later.
                        $override = trim((string) ($saved[$field] ?? ''));

                        if ($override !== '') {
                            $row[$field] = $override;
                        }
                    }
                    $row['included'] = (bool) ($saved['included'] ?? true);
                }

                return $row;
            })
            ->values();

        $manualRows = collect($data['manual_rows'] ?? [])->map(fn ($row) => $this->normalizeSavedRow(
            $row,
            (string) ($row['id'] ?? uniqid('manual-')),
            true,
        ));

        // A saved row whose source batch_students record no longer exists
        // (archived, then purged 30+ days later) would otherwise vanish
        // silently, since it is only ever looked up while iterating the live
        // roster above. Render it from its last-saved snapshot instead —
        // is_manual stays false so a future save keeps writing it back under
        // its original id. Same fix already carried by HteReportController.
        $liveIds = $enrollments->pluck('id')->map(fn ($id) => (int) $id)->all();
        $orphanedRows = $savedRows
            ->reject(fn ($saved, $id) => in_array((int) $id, $liveIds, true) || in_array((int) $id, $deletedIds, true))
            ->map(fn ($saved, $id) => $this->normalizeSavedRow($saved, (int) $id, false))
            ->values();

        return $candidateRows->concat($orphanedRows)->concat($manualRows)->values()->all();
    }

    /**
     * The seven roster columns of the official form, in document order.
     *
     * @return array<int, string>
     */
    private static function rowFields(): array
    {
        return [
            'last_name',
            'first_name',
            'middle_initial',
            'program_year',
            'contact_number',
            'parent_guardian_name',
            'parent_guardian_contact',
        ];
    }

    /**
     * @param  array<string, mixed>  $saved
     * @return array<string, mixed>
     */
    private function normalizeSavedRow(array $saved, int|string $id, bool $isManual): array
    {
        $row = ['id' => $id];

        foreach (self::rowFields() as $field) {
            $row[$field] = (string) ($saved[$field] ?? '');
        }

        $row['included'] = (bool) ($saved['included'] ?? true);
        $row['is_manual'] = $isManual;

        return $row;
    }

    /**
     * Latest information sheet per rostered student, keyed by student id.
     * Ordering ascending and keying by student lets the last one win, which is
     * the newest — the same "latest sheet" rule the coordinator queue uses.
     *
     * @param  EloquentCollection<int, BatchStudent>  $enrollments
     * @return Collection<int, StudentInformationSheet>
     */
    private function infoSheetsFor(EloquentCollection $enrollments): Collection
    {
        $studentIds = $enrollments->pluck('student_id')->unique()->values();

        if ($studentIds->isEmpty()) {
            return collect();
        }

        return StudentInformationSheet::whereIn('student_id', $studentIds)
            ->orderBy('id')
            ->get()
            ->keyBy('student_id');
    }

    /**
     * One roster row, sourced from the student's own information sheet — the
     * same place the individual sheet reads — with the student profile and
     * users.name as progressive fallbacks.
     *
     * @return array<string, mixed>
     */
    private function mapCandidate(BatchStudent $enrollment, ?StudentInformationSheet $infoSheet): array
    {
        $personal = $infoSheet?->personal_info ?? [];
        $academic = $infoSheet?->academic_info ?? [];
        $profile = $enrollment->student?->studentProfile;

        [$fallbackFirst, $fallbackLast] = $this->splitName($enrollment->student?->name);

        // Prefer whatever the student actually typed on their own sheet; the
        // profile is only a fallback for a student who has no sheet yet.
        $middle = trim((string) ($personal['middle_name'] ?? '')) ?: trim((string) ($profile?->middle_name ?? ''));

        return [
            'id' => (int) $enrollment->id,
            'last_name' => trim((string) ($personal['last_name'] ?? '')) ?: $fallbackLast,
            'first_name' => trim((string) ($personal['first_name'] ?? '')) ?: $fallbackFirst,
            // MI = the middle name's first letter, capitalized. No trailing
            // period — the form's column is literally headed "MI".
            'middle_initial' => $this->middleInitial($middle),
            'program_year' => $this->formatProgramYear($enrollment, $academic, $profile?->year_level),
            'contact_number' => trim((string) ($personal['contact_number'] ?? $profile?->contact_number ?? '')),
            'parent_guardian_name' => trim((string) ($personal['parent_guardian_name'] ?? '')),
            'parent_guardian_contact' => trim((string) ($personal['parent_guardian_contact'] ?? '')),
            'included' => true,
            'is_manual' => false,
        ];
    }

    /**
     * "BSIT 4th Year" — the program CODE plus the prettified year level.
     *
     * The individual sheet prints academic_info.program_course (the program's
     * full NAME) concatenated with the raw "4th-year" dropdown value, which is
     * far too wide for this form's narrow column. Same source data, tighter
     * rendering; the individual sheet is deliberately left as it is.
     *
     * @param  array<string, mixed>  $academic
     */
    private function formatProgramYear(BatchStudent $enrollment, array $academic, ?string $profileYear): string
    {
        $program = $enrollment->batch?->program;
        $code = trim((string) ($program?->code ?: ($program?->name ?? '')));

        $year = trim((string) ($academic['year_level'] ?? $profileYear ?? ''));
        $year = $year === '' ? '' : ucwords(str_replace('-', ' ', $year));

        return trim($code.' '.$year);
    }

    /**
     * The capitalized first letter of a middle name ("Bautista" -> "B").
     *
     * mb_* throughout so a non-ASCII first character survives intact rather
     * than being sliced mid-byte, and any leading punctuation/whitespace is
     * skipped so a stored " de la Cruz" still yields "D" and not a space.
     */
    private function middleInitial(?string $middleName): string
    {
        $middle = trim((string) $middleName);

        if ($middle === '' || ! preg_match('/\p{L}/u', $middle, $matches)) {
            return '';
        }

        return mb_strtoupper($matches[0]);
    }

    /**
     * @return array{0: string, 1: string} [first, last]
     */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);

        if ($name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        if (count($parts) < 2) {
            return [$name, ''];
        }

        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    /**
     * The coordinator-typed INTERNSHIP COMPANY INFORMATION block: saved values
     * when present, otherwise sensible pre-fills from the company record and
     * the rostered batches so the form does not start blank.
     *
     * @param  EloquentCollection<int, BatchStudent>  $enrollments
     * @return array<string, mixed>
     */
    private function companyBlock(Company $company, EloquentCollection $enrollments, ?GroupInfoSheet $sheet): array
    {
        $saved = $sheet?->sheet_data['company'] ?? [];

        $batches = $enrollments->pluck('batch')->filter();
        $starts = $batches->pluck('start_date')->filter();
        $ends = $batches->pluck('end_date')->filter();

        $defaults = [
            'host_company' => (string) $company->name,
            'company_address' => (string) ($company->address ?? ''),
            'company_signatory_moa' => (string) ($company->head_name ?? ''),
            'office_designation' => (string) ($company->department_head ?? ''),
            'supervisor_name' => (string) ($company->loginSupervisor?->user?->name ?? ''),
            'supervisor_contact' => (string) ($company->head_contact_number ?? $company->contact_number ?? ''),
            'intern_duty_schedule' => '',
            'area_assigned' => (string) ($enrollments->pluck('assigned_division')->filter()->first() ?? ''),
            'ojt_start_date' => $starts->min()?->format('Y-m-d') ?? '',
            'ojt_end_date' => $ends->max()?->format('Y-m-d') ?? '',
        ];

        $block = [];

        foreach ($defaults as $key => $default) {
            $value = trim((string) ($saved[$key] ?? ''));
            $block[$key] = $value !== '' ? $value : $default;
        }

        return $block;
    }

    /**
     * The editable header line: whatever the coordinator saved, else the
     * reference form's own wording as the starting value.
     */
    private function departmentLine(?GroupInfoSheet $sheet): string
    {
        $saved = trim((string) ($sheet?->sheet_data['header']['department_line'] ?? ''));

        return $saved !== '' ? $saved : self::DEFAULT_DEPARTMENT_LINE;
    }
}
