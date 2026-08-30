<?php

namespace App\Http\Controllers\Concerns;

/**
 * Registers the shipped Carlito faces on a dompdf instance, for the documents
 * whose reference form is set in a Microsoft humanist sans.
 *
 * Carlito is metric-compatible with Calibri and is SIL OFL licensed, so unlike
 * Calibri (and unlike Aptos, Office's current default) it can actually ship in
 * this repository — `resources/fonts/Carlito-{Regular,Bold}.ttf` plus its
 * OFL.txt.
 *
 * Extracted from BuildsWeeklyActivityLogPdf, which owned this privately, so the
 * SIPP annexes can use the identical registration rather than a second copy
 * that could drift on the two details below. Both of those are load-bearing:
 *
 * 1. **Registration happens in PHP, not through `@font-face`.** A CSS `url()`
 *    pointing at a local `.ttf` goes through dompdf's URL resolver, which does
 *    not survive a Windows drive-letter path.
 * 2. **It is best-effort.** A missing font file is skipped rather than thrown,
 *    so every blade's own `font-family` stack must end in a real fallback —
 *    a missing face then degrades the type instead of 500-ing the download.
 *
 * dompdf caches the parsed metrics into `storage/fonts/` on first render; that
 * directory is committed and the Dockerfile already chowns `storage`.
 */
trait RegistersCarlitoFonts
{
    protected const CARLITO_FAMILY = 'carlito';

    /** @var array<string, string> */
    protected const CARLITO_FILES = [
        'normal' => 'resources/fonts/Carlito-Regular.ttf',
        'bold' => 'resources/fonts/Carlito-Bold.ttf',
    ];

    /**
     * @param  mixed  $pdf  the laravel-dompdf wrapper
     */
    protected function registerCarlitoFonts(mixed $pdf): void
    {
        $dompdf = $pdf->getDomPDF();

        // laravel-dompdf's shipped config turns subsetting OFF, which is
        // harmless while every PDF uses a base-14 font but embeds the whole
        // 682KB face the moment one does not. Switched on per INSTANCE (the
        // option lives on the instance, not the container binding), so no
        // other PDF in the project is affected.
        $dompdf->getOptions()->setIsFontSubsettingEnabled(true);

        $metrics = $dompdf->getFontMetrics();

        foreach (self::CARLITO_FILES as $weight => $relativePath) {
            $path = base_path($relativePath);

            if (! is_file($path)) {
                continue;
            }

            $metrics->registerFont(
                ['family' => self::CARLITO_FAMILY, 'style' => 'normal', 'weight' => $weight],
                $path
            );
        }
    }
}
