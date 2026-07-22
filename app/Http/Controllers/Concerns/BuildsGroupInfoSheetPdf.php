<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Company;
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
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $companyBlock
     */
    protected function renderGroupInfoSheetPdf(
        Company $company,
        string $academicYear,
        string $departmentLine,
        array $rows,
        array $companyBlock,
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
}
