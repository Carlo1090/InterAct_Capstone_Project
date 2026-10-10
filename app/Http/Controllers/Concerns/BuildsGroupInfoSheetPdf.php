<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Company;
use App\Services\StaticMapService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared renderer for the GROUP Student Information Sheet PDF — the
 * per-company companion to the individual sheet. Deliberately mirrors
 * BuildsInfoSheetPdf move for move (same base64 data-URI logo with the
 * labeled-placeholder fallback, same loadView -> download shape) so the two
 * official documents can never drift apart in how they are produced.
 *
 * @phpstan-type GroupRow array<string, mixed>
 */
trait BuildsGroupInfoSheetPdf
{
    /**
     * The "Sketch of Internship Company Location" box, in points, as measured
     * off the reference (518.4 x 189.65pt). THESE MUST TRACK THE BLADE'S
     * .sketch-box — the map is rasterised to exactly this box and then sized
     * to fill it, so a change on one side alone stretches the map. The
     * coordinator's on-screen preview (CoordinatorLocationController) is drawn
     * at the same 2.733:1.
     */
    private const GROUP_SKETCH_WIDTH_PT = 518.4;

    private const GROUP_SKETCH_HEIGHT_PT = 189.65;

    /** Rendered at 2x the printed size (~144dpi), as the individual sheet is. */
    private const GROUP_SKETCH_SCALE = 2;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $companyBlock
     * @param  array{lat: float, lng: float, zoom: int}|null  $location
     */
    protected function renderGroupInfoSheetPdf(
        Company $company,
        string $academicYear,
        string $departmentLine,
        array $rows,
        array $companyBlock,
        ?array $location = null,
    ): Response {
        // No logo is loaded here, unlike BuildsInfoSheetPdf: the client
        // reference for the GROUP sheet contains no image, and its absence is
        // what lets a full 12-intern roster share one page with the company
        // block and the sketch box.
        //
        // US Letter, matching the reference's 612x792pt MediaBox — the blade's
        // measurements are in those points, and dompdf would otherwise default
        // to A4 (595x842) and shift every column.
        $pdf = Pdf::loadView('pdf.info-sheet-group', [
            'departmentLine' => $departmentLine,
            'rows' => $rows,
            'company' => $companyBlock,
            'locationMap' => $this->groupSketchMap($location),
        ])->setPaper('letter', 'portrait');

        // The reference's "Page N of M" footer, at its measured position
        // (x 490.75, baseline 51.62 from the foot = 740.4 from the head).
        // Stamped through the canvas rather than CSS because dompdf only knows
        // the page total after laying the whole document out — counter(pages)
        // in CSS renders as 0. download() below reuses this render rather than
        // repeating it, so the stamp survives.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $dompdf->getCanvas()->page_text(
            490.75,
            740.4,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $dompdf->getFontMetrics()->getFont('Helvetica'),
            11,
            [0, 0, 0],
        );

        $slug = Str::slug($company->name) ?: 'company';

        return $pdf->download("group-student-information-sheet-{$slug}-{$academicYear}.pdf");
    }

    /**
     * The sketch box's map as a data URI, or null — no location, the map
     * service off, or the tiles unreachable — in which case the blade prints
     * the blank box the paper form has always had.
     *
     * No caption line under it, unlike the individual sheet: the tile credit
     * is already burned into the image, and a 12-intern roster leaves this
     * page no room for another line.
     *
     * @param  array{lat: float, lng: float, zoom: int}|null  $location
     */
    private function groupSketchMap(?array $location): ?string
    {
        if ($location === null) {
            return null;
        }

        $maps = app(StaticMapService::class);

        return $maps->dataUri(
            (float) $location['lat'],
            (float) $location['lng'],
            $maps->clampZoom($location['zoom'] ?? null),
            (int) round(self::GROUP_SKETCH_WIDTH_PT * self::GROUP_SKETCH_SCALE),
            (int) round(self::GROUP_SKETCH_HEIGHT_PT * self::GROUP_SKETCH_SCALE),
        );
    }
}
