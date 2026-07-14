<?php

namespace App\Http\Controllers\Concerns;

use App\Models\StudentInformationSheet;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared renderer for the individual Student Information Sheet PDF, so the
 * student's own download and the coordinator's in-scope download produce the
 * identical Mater Dei College document (pdf.info-sheet blade).
 */
trait BuildsInfoSheetPdf
{
    protected function renderInfoSheetPdf(StudentInformationSheet $sheet, User $student): Response
    {
        // dompdf renders base64 data URIs reliably; fall back to a labeled
        // placeholder box (handled in the blade) if the logo file is absent.
        $logoPath = public_path('images/mdc-logo.png');
        $logo = is_file($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        $pdf = Pdf::loadView('pdf.info-sheet', [
            'logo' => $logo,
            'studentName' => $student->name,
            'personal' => $sheet->personal_info ?? [],
            'academic' => $sheet->academic_info ?? [],
            'ojt' => $sheet->ojt_info ?? [],
        ]);

        return $pdf->download('student-information-sheet-'.$student->id.'.pdf');
    }
}
