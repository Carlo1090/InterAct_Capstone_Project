<?php

namespace App\Http\Controllers\Supervisor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Supervisor\Concerns\ScopesSupervisorWork;
use App\Http\Requests\Supervisor\StoreGeofenceRequest;
use App\Http\Requests\Supervisor\UpdateGeofenceRequest;
use App\Models\Company;
use App\Models\CompanyGeofence;
use App\Models\SystemLog;
use App\Models\User;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The company supervisor's clock-in sites and their printable QR codes.
 *
 * A site is anchored to coordinates the supervisor's own device reported
 * while they were standing at the workplace, which is why creating one
 * requires location access. Coordinates are write-once: re-anchoring means
 * creating a new site, so a fence can never be quietly moved without fresh
 * capture evidence (see UpdateGeofenceRequest).
 */
class DtrGeofenceController extends Controller
{
    use ScopesSupervisorWork;

    public function index(Request $request): JsonResponse
    {
        $companyIds = $this->supervisedCompanyIds($request->user());

        $geofences = CompanyGeofence::with('company:id,name')
            ->whereIn('company_id', $companyIds)
            ->orderByDesc('is_active')
            ->orderBy('label')
            ->get()
            ->map(fn (CompanyGeofence $geofence) => $this->present($geofence));

        return response()->json([
            'geofences' => $geofences,
            // The supervisor picks which company a new site belongs to only
            // when their login represents more than one; the SPA hides the
            // select otherwise.
            'companies' => $this->supervisedCompanies($request->user()),
        ]);
    }

    public function store(StoreGeofenceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $this->authorizeCompany($request, (int) $validated['company_id']);

        $geofence = CompanyGeofence::create([
            'company_id' => $validated['company_id'],
            'created_by' => $request->user()->id,
            'label' => $validated['label'],
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'radius_meters' => $validated['radius_meters'] ?? 150,
            'captured_accuracy' => $validated['captured_accuracy'] ?? null,
            'is_active' => true,
        ]);

        SystemLog::record(
            'DTR Site Created',
            "Created clock-in site \"{$geofence->label}\" with a {$geofence->radius_meters}m radius."
        );

        return response()->json([
            'geofence' => $this->present($geofence->fresh('company')),
            'message' => 'Clock-in site created. Download the QR code and post it where interns arrive.',
        ], 201);
    }

    public function update(UpdateGeofenceRequest $request, CompanyGeofence $geofence): JsonResponse
    {
        $this->authorizeCompany($request, $geofence->company_id);

        $geofence->fill($request->validated())->save();

        return response()->json([
            'geofence' => $this->present($geofence->fresh('company')),
            'message' => 'Clock-in site updated.',
        ]);
    }

    /**
     * Retire a site rather than erase it. dtr_sessions reference the fence
     * for their audit trail, and a hard delete would strip the location
     * context off every punch ever taken there.
     */
    public function destroy(Request $request, CompanyGeofence $geofence): JsonResponse
    {
        $this->authorizeCompany($request, $geofence->company_id);

        $geofence->is_active = false;
        $geofence->save();

        SystemLog::record('DTR Site Retired', "Retired clock-in site \"{$geofence->label}\".");

        return response()->json(['message' => 'Clock-in site retired. Its existing records are unchanged.']);
    }

    /**
     * The printable code. SVG by default — it needs no GD and stays sharp at
     * any print size, which matters for something taped to a wall. PNG is
     * offered for tools that will not place an SVG.
     */
    public function qr(Request $request, CompanyGeofence $geofence): Response
    {
        $this->authorizeCompany($request, $geofence->company_id);

        $wantsPng = $request->query('format') === 'png';
        $writer = $wantsPng ? new PngWriter : new SvgWriter;

        $result = $writer->write(new QrCode(
            data: $this->scanUrl($geofence),
            // High correction, not the library default of Low: this code is
            // printed and taped to a wall, where it will pick up scuffs,
            // glare and a torn corner. High tolerates ~30% damage.
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 600,
            margin: 20,
        ));

        $filename = 'dtr-'.str($geofence->label)->slug().'.'.($wantsPng ? 'png' : 'svg');

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
            // attachment, not inline: the point of this endpoint is a file
            // the supervisor prints.
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * The URL encoded in the QR. It points at the SPA, not the API, because
     * the student opens it with their phone's own camera app — the landing
     * page then asks for location and posts the punch.
     */
    private function scanUrl(CompanyGeofence $geofence): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/student/dtr/scan?s='.$geofence->token;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CompanyGeofence $geofence): array
    {
        return [
            'id' => $geofence->id,
            'company_id' => $geofence->company_id,
            'company' => $geofence->company?->name,
            'label' => $geofence->label,
            'latitude' => $geofence->latitude,
            'longitude' => $geofence->longitude,
            'radius_meters' => $geofence->radius_meters,
            'captured_accuracy' => $geofence->captured_accuracy,
            // Surfaced so the supervisor can see a fence was anchored on a
            // vague fix and re-create it, rather than wondering why nobody
            // can clock in.
            'accuracy_is_poor' => $geofence->captured_accuracy !== null
                && $geofence->captured_accuracy > CompanyGeofence::POOR_ACCURACY_METRES,
            'is_active' => $geofence->is_active,
            'scan_url' => $this->scanUrl($geofence),
            'created_at' => $geofence->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function supervisedCompanies(User $supervisor): array
    {
        return Company::whereIn('id', $this->supervisedCompanyIds($supervisor))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($company) => ['id' => $company->id, 'name' => $company->name])
            ->all();
    }

    private function authorizeCompany(Request $request, int $companyId): void
    {
        abort_unless(
            $this->supervisedCompanyIds($request->user())->contains($companyId),
            403,
            'That company is not one you represent.'
        );
    }
}
