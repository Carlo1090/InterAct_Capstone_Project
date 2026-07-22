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
        // dompdf renders base64 data URIs reliably; fall back to a labeled
        // placeholder box (handled in the blade) if the logo file is absent.
        $logoPath = public_path('images/mdc-logo.png');
        $logo = is_file($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        $pdf = Pdf::loadView('pdf.info-sheet-group', [
            'logo' => $logo,
            'departmentLine' => $departmentLine,
            'rows' => $rows,
            'company' => $companyBlock,
        ]);

        $slug = Str::slug($company->name) ?: 'company';

        return $pdf->download("group-student-information-sheet-{$slug}-{$academicYear}.pdf");
    }
}
