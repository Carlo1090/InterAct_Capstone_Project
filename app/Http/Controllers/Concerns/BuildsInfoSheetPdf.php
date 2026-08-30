<?php

namespace App\Http\Controllers\Concerns;

use App\Models\StudentInformationSheet;
use App\Models\User;
use App\Services\StaticMapService;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared renderer for the individual Student Information Sheet PDF, so the
 * student's own download and the coordinator's in-scope download produce the
 * identical Mater Dei College document (pdf.info-sheet blade).
 */
trait BuildsInfoSheetPdf
{
    /**
     * The "Sketch of Internship Company Location" box, in points.
     *
     * A4 is 595.28pt wide and the blade's @page margin is 1.6cm (45.35pt) a
     * side, so the box spans the full 504.57pt content column; 90mm is 255.12pt
     * tall. THESE MUST TRACK THE BLADE — a margin change here without one there
     * stretches the map, because the <img> is sized to fill the box.
     */
    private const SKETCH_WIDTH_PT = 504.57;

    private const SKETCH_HEIGHT_PT = 255.12;

    /**
     * Rendered at 2x the printed size (~144dpi). The tiles are raster art, so
     * 1:1 prints visibly soft; past 2x the base64 payload grows faster than the
     * page gets better.
     */
    private const SKETCH_SCALE = 2;

    protected function renderInfoSheetPdf(StudentInformationSheet $sheet, User $student): Response
    {
        // dompdf renders base64 data URIs reliably; fall back to a labeled
        // placeholder box (handled in the blade) if the logo file is absent.
        $logoPath = public_path('images/mdc-logo.png');
        $logo = is_file($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        $ojt = $sheet->ojt_info ?? [];

        $pdf = Pdf::loadView('pdf.info-sheet', [
            'logo' => $logo,
            'studentName' => $student->name,
            'personal' => $sheet->personal_info ?? [],
            'academic' => $sheet->academic_info ?? [],
            'ojt' => $ojt,
            ...$this->infoSheetLocationMap($ojt),
        ]);

        return $pdf->download('student-information-sheet-'.$student->id.'.pdf');
    }

    /**
     * The pinned company location, rendered flat for the sketch box.
     *
     * Returns nulls whenever there is no pin, the map service is switched off,
     * or the tiles could not be fetched — the blade then prints the blank box
     * the paper form has always had. A map is a nice-to-have on this document;
     * being able to download it at all is not.
     */
    protected function infoSheetLocationMap(array $ojt): array
    {
        $maps = app(StaticMapService::class);

        $lat = $ojt['location_lat'] ?? null;
        $lng = $ojt['location_lng'] ?? null;

        if (! $maps->isPinnable($lat, $lng)) {
            return ['locationMap' => null, 'locationNote' => null];
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        $image = $maps->dataUri(
            $lat,
            $lng,
            $maps->clampZoom($ojt['location_zoom'] ?? null),
            (int) round(self::SKETCH_WIDTH_PT * self::SKETCH_SCALE),
            (int) round(self::SKETCH_HEIGHT_PT * self::SKETCH_SCALE),
        );

        if ($image === null) {
            return ['locationMap' => null, 'locationNote' => null];
        }

        // The coordinates are printed as well as drawn: a photocopied sheet is
        // still usable if someone wants to type the point into their own maps
        // app, and the attribution is a condition of the tile licence.
        $note = trim(($ojt['location_label'] ?? '').' ') !== ''
            ? $ojt['location_label'].'  ·  '
            : '';

        return [
            'locationMap' => $image,
            'locationNote' => $note.sprintf('Pinned location: %.6f, %.6f  ·  Map data %s', $lat, $lng, $maps->attribution()),
        ];
    }
}
