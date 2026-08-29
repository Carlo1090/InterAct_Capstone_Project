<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Concerns\BuildsInfoSheetPdf;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesStudentEnrollment;
use App\Http\Requests\Student\StoreInfoSheetRequest;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Notification;
use App\Models\StudentInformationSheet;
use App\Services\StaticMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class StudentInfoSheetController extends Controller
{
    use BuildsInfoSheetPdf;
    use ResolvesStudentEnrollment;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $sheet = StudentInformationSheet::where('student_id', $user->id)->latest('id')->first();

        if ($sheet) {
            return response()->json($sheet);
        }

        // Backward-compat: a student who predates the intake flow (directly
        // enrolled, no scaffolded sheet) gets a prefilled empty scaffold.
        $enrollment = $this->activeEnrollment($user->id);
        $profile = $user->studentProfile;
        [$firstName, $lastName] = array_pad(explode(' ', $user->name, 2), 2, '');

        return response()->json([
            'id' => null,
            'submission_status' => null,
            'rejection_reason' => null,
            'submitted_at' => null,
            'personal_info' => [
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_name' => $profile?->middle_name,
                'parent_guardian_name' => null,
                'parent_guardian_contact' => null,
                'date_of_birth' => $profile?->date_of_birth?->toDateString(),
                'sex' => $profile?->sex,
                'home_address' => $profile?->home_address,
                'contact_number' => $profile?->contact_number,
                'email' => $user->email,
                'student_id_number' => $user->student_id_number,
            ],
            'academic_info' => [
                'program_course' => $user->program?->name,
                'year_level' => $profile?->year_level,
                'department' => $user->program?->department?->name,
                'internship_coordinator' => $enrollment?->batch?->coordinator?->name,
                'coordinator_contact_no' => null,
            ],
            'ojt_info' => [
                'host_company' => $enrollment?->company?->name,
                'company_address' => $enrollment?->company?->address,
                'company_signatory_moa' => null,
                'office_designation' => null,
                'supervisor_name' => $enrollment?->supervisor?->name,
                'supervisor_contact' => null,
                'area_assigned' => $enrollment?->assigned_division,
                'intern_duty_schedule' => null,
                'ojt_start_date' => $enrollment?->batch?->start_date?->toDateString(),
                'ojt_end_date' => $enrollment?->batch?->end_date?->toDateString(),
            ],
            'emergency_contact' => null,
        ]);
    }

    public function store(StoreInfoSheetRequest $request): JsonResponse
    {
        $user = $request->user();
        $sheet = StudentInformationSheet::where('student_id', $user->id)->latest('id')->first();

        // No scaffolded sheet (legacy path): fall back to the active enrollment's
        // batch. Without either, the student has no assigned batch to write against.
        if (! $sheet) {
            $enrollment = $this->activeEnrollment($user->id);

            if (! $enrollment) {
                return response()->json([
                    'message' => 'Your account has no assigned batch yet. Please contact your coordinator.',
                ], 422);
            }

            $sheet = new StudentInformationSheet([
                'student_id' => $user->id,
                'batch_id' => $enrollment->batch_id,
            ]);
        }

        $validated = $request->validated();
        $status = $validated['status'];
        unset($validated['status']);

        // A sheet approved before this save is "enrolled" — Program & Year, and
        // the assigned Company, drove the coordinator's Accept step and must stay
        // fixed from here on; everything else on the sheet remains editable.
        $wasApproved = $sheet->exists && $sheet->submission_status === 'approved';

        // Captured before fill()/save() overwrite it — used after saving to
        // detect a genuine new-submission transition (see notification below).
        $previousStatus = $sheet->submission_status;

        // ojt_info is `present` (may be empty) — guarantee the NOT-NULL column.
        $validated['ojt_info'] = $validated['ojt_info'] ?? [];

        // Program & Year and the Internship Coordinator are read-only on the
        // form — re-derive them from the intended batch so they can't be spoofed.
        $batch = Batch::with(['program.department', 'coordinator'])->find($sheet->batch_id);
        $validated['academic_info'] = [
            ...($validated['academic_info'] ?? []),
            'program_course' => $batch?->program?->name,
            'department' => $batch?->program?->department?->name,
            'internship_coordinator' => $batch?->coordinator?->name,
            // Year locks the same way once enrolled — re-derive from the existing
            // sheet instead of trusting the incoming value.
            'year_level' => $wasApproved
                ? ($sheet->academic_info['year_level'] ?? null)
                : ($validated['academic_info']['year_level'] ?? null),
        ];

        if ($wasApproved) {
            // Company assignment is locked post-enrollment (it drove the Accept
            // step's batch_students placement) — everything else on ojt_info
            // (address, signatory, supervisor, schedule, dates) stays editable.
            $validated['ojt_info']['company_id'] = $sheet->ojt_info['company_id'] ?? null;
            $validated['ojt_info']['host_company'] = $sheet->ojt_info['host_company'] ?? null;
        }

        $sheet->fill([
            ...$validated,
            // Post-enrollment edits are profile maintenance, not a new gate
            // submission — never move an approved sheet back to draft/submitted,
            // or EnsureInfoSheetApproved would re-gate an already-enrolled student.
            'submission_status' => $wasApproved ? 'approved' : $status,
            'submitted_at' => $wasApproved ? $sheet->submitted_at : ($status === 'submitted' ? now() : null),
            'rejection_reason' => $wasApproved ? $sheet->rejection_reason : null,
        ]);
        $sheet->save();

        // Notify the batch's coordinator only on a genuine new-submission
        // transition — not on every draft autosave, and not on a resave that
        // was pinned back to 'approved' by the wasApproved branch above.
        // previousStatus !== 'submitted' also blocks a duplicate notification
        // if a submitted-but-not-yet-reviewed sheet gets resubmitted as-is.
        if ($sheet->submission_status === 'submitted' && $previousStatus !== 'submitted' && $batch?->coordinator) {
            Notification::create([
                'user_id' => $batch->coordinator->id,
                'title' => 'Student Information Sheet Submitted',
                'message' => "{$user->name} submitted their Student Information Sheet for review.",
                'type' => 'in_app',
                'is_read' => false,
            ]);
        }

        return response()->json($sheet);
    }

    /**
     * The coordinator-curated company list backing the "Name of Company"
     * dropdown — the one constrained field on the info sheet.
     */
    public function companies(): JsonResponse
    {
        return response()->json(
            Company::where('is_active', true)->orderBy('name')->get(['id', 'name'])
        );
    }

    /**
     * What the location picker needs to draw itself.
     *
     * Served rather than hardcoded in the SPA so the picker and the PDF can
     * never disagree about which map they are showing — both read
     * config/staticmap.php, exactly the reasoning behind lib/dtr.ts on the
     * clock-in side.
     *
     * Deliberately does NOT seed from company_geofences. Those coordinates are
     * where a company's QR clock-in fence is anchored, and handing every
     * student the precise location of every company's fence would lower the
     * cost of spoofing a punch — the one thing that scheme's honesty rests on.
     * The picker opens on the college instead and the student moves the pin.
     */
    public function locationOptions(): JsonResponse
    {
        $maps = app(StaticMapService::class);

        return response()->json([
            'enabled' => $maps->isEnabled(),
            'tile_url' => config('staticmap.tile_url'),
            'attribution' => $maps->attribution(),
            'default_center' => config('staticmap.default_center'),
            'min_zoom' => StaticMapService::MIN_ZOOM,
            'max_zoom' => StaticMapService::MAX_ZOOM,
            'search_enabled' => (bool) config('staticmap.geocoder_url'),
        ]);
    }

    /**
     * The pinned location as a small image, for the collapsed state on the form.
     *
     * This is what makes the map cheap: the info sheet no longer mounts a map
     * library and a dozen live tiles just to say "you pinned this place". It
     * shows ONE cached PNG — and because it is rendered at the printed box's
     * own aspect ratio, it is literally a preview of what the PDF will contain
     * rather than an approximation of it.
     *
     * The canvas size is fixed server-side on purpose. A caller-controlled
     * width and height is a way to make the server fetch arbitrarily many tiles
     * from somebody else's free service on demand.
     */
    public function locationPreview(Request $request): Response
    {
        $maps = app(StaticMapService::class);

        $lat = $request->query('lat');
        $lng = $request->query('lng');

        abort_unless($maps->isPinnable($lat, $lng), 404);

        $png = $maps->render(
            (float) $lat,
            (float) $lng,
            $maps->clampZoom($request->query('zoom')),
            StaticMapService::PREVIEW_WIDTH,
            StaticMapService::PREVIEW_HEIGHT,
        );

        // 404 rather than a placeholder image: the <img> then simply fails and
        // the form falls back to showing the coordinates as text, instead of
        // presenting a grey rectangle as if it were the location.
        abort_if($png === null, 404);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            // A pin does not move unless the student moves it, and the URL
            // carries the coordinates — so the browser never needs to ask twice.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Look an address up so the student does not have to drag the map across a
     * province to find their company.
     *
     * Nominatim is free and keyless, but its usage policy caps callers at about
     * one request a second and explicitly forbids autocomplete-as-you-type.
     * Three things keep us inside it: the UI only searches on an explicit
     * submit, the route is throttled, and every answer is cached for a day —
     * a cohort of interns all looking up the same handful of local companies
     * therefore makes a handful of requests, not hundreds.
     */
    public function locationSearch(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 3) {
            return response()->json(['results' => []]);
        }

        $endpoint = (string) config('staticmap.geocoder_url');

        if ($endpoint === '') {
            return response()->json(['results' => [], 'unavailable' => true]);
        }

        $results = Cache::remember(
            'staticmap:geocode:'.sha1(mb_strtolower($query)),
            now()->addDay(),
            function () use ($endpoint, $query) {
                try {
                    $response = Http::withHeaders([
                        // Required by the Nominatim usage policy; an unidentified
                        // caller gets blocked outright.
                        'User-Agent' => (string) config('staticmap.user_agent'),
                    ])->timeout((int) config('staticmap.timeout'))->get($endpoint, [
                        'q' => $query,
                        'format' => 'jsonv2',
                        'limit' => 5,
                        'countrycodes' => config('staticmap.geocoder_country_codes'),
                    ]);
                } catch (\Throwable) {
                    return null;
                }

                if (! $response->successful()) {
                    return null;
                }

                return collect($response->json())
                    ->map(fn ($row) => [
                        'label' => $row['display_name'] ?? null,
                        'lat' => isset($row['lat']) ? (float) $row['lat'] : null,
                        'lng' => isset($row['lon']) ? (float) $row['lon'] : null,
                    ])
                    ->filter(fn ($row) => $row['label'] && $row['lat'] !== null && $row['lng'] !== null)
                    ->values()
                    ->all();
            }
        );

        // A null answer is a lookup that failed, not a search with no matches —
        // don't cache the failure, and tell the UI to offer the manual pin.
        if ($results === null) {
            Cache::forget('staticmap:geocode:'.sha1(mb_strtolower($query)));

            return response()->json(['results' => [], 'unavailable' => true]);
        }

        return response()->json(['results' => $results]);
    }

    /**
     * Download the student's own information sheet as the official MDC PDF.
     */
    public function pdf(Request $request): Response
    {
        $user = $request->user();
        $sheet = StudentInformationSheet::where('student_id', $user->id)->latest('id')->first();

        abort_if($sheet === null, 404, 'You have not started your information sheet yet.');

        return $this->renderInfoSheetPdf($sheet, $user);
    }
}
