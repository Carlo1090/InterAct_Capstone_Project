@php
    use App\Support\SippAnnexLayout as L;

    // Content-box widths: dompdf adds cell padding on top of a written width.
    [$colIssues, $colSolutions, $colRecommendations] = L::contentWidths(L::ANNEX_C_COLUMNS);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Annual SIPP Report</title>
    {{--
        Annex "C", measured from docs/reference/ANNEX C - SIPP REPORT (2).docx.
        Every number below comes from App\Support\SippAnnexLayout, which carries
        the reference's own twip figures — do not "tidy" them into round values.

        The page is 8.5in x 13in LANDSCAPE (Philippine long bond, turned), set on
        the Pdf instance in AnnualSippReportController::pdf(). Before that call
        existed this document rendered on dompdf's A4 PORTRAIT default, which
        left the three-column table 462pt wide instead of 792pt.
    --}}
    <style>
        @page { margin: {{ L::MARGIN }}pt; }

        /*
         * Carlito stands in for the reference's Aptos — see SippAnnexLayout for
         * why. The Helvetica fallback matters: font registration is best-effort,
         * so a missing .ttf must degrade the type rather than fail the download.
         */
        body {
            font-family: carlito, "Carlito", Helvetica, Arial, sans-serif;
            font-size: {{ L::BODY_PT }}pt;
            color: #000;
        }

        .annex { text-align: right; font-weight: bold; margin-bottom: 6pt; }

        .header-lines { text-align: center; }
        .header-lines p { margin: 0; font-weight: bold; text-transform: uppercase; }
        .header-lines .ay { margin-top: 8pt; }

        .meta { margin: 16pt 0 10pt; }
        .meta p { margin: 0 0 4pt; }

        table.sipp {
            width: {{ L::tableWidth(L::ANNEX_C_COLUMNS) }}pt;
            border-collapse: collapse;
            margin-top: 4pt;
        }

        /*
         * Vertical padding is 3pt rather than the reference's literal 0: Word
         * puts its in-cell breathing room on the PARAGRAPH (spacing-after), which
         * has no equivalent once each cell holds one plain block. Horizontal
         * padding IS the reference's 108 twips exactly.
         */
        table.sipp th,
        table.sipp td {
            border: {{ L::RULE }}pt solid #000;
            padding: 3pt {{ L::CELL_PAD_X }}pt;
            font-size: {{ L::BODY_PT }}pt;
            vertical-align: top;
            text-align: left;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        table.sipp th { font-weight: bold; text-align: center; }

        table.footer { width: 100%; margin-top: 30pt; }
        table.footer td { width: 50%; vertical-align: top; padding-right: 12pt; }
        .sig-label { padding-bottom: 40pt; font-weight: bold; }
        .sig-name { font-weight: bold; text-transform: uppercase; }
        .sig-caption { font-size: 10pt; font-style: italic; }
    </style>
</head>
<body>
    <div class="annex">Annex &ldquo;C&rdquo;</div>

    <div class="header-lines">
        <p>ANNUAL REPORT IN THE IMPLEMENTATION OF</p>
        <p>STUDENT INTERNSHIP PROGRAM IN THE PHILIPPINES (SIPP)</p>
        <p class="ay">AY: {{ $academicYear }}</p>
    </div>

    <div class="meta">
        <p><strong>HEI:</strong> MATER DEI COLLEGE, INC.</p>
        <p><strong>ADDRESS:</strong> CABULIJAN, TUBIGON, BOHOL, PHILIPPINES</p>
        <p><strong>DEGREE PROGRAM:</strong> {{ $meta['heading'] }}</p>
    </div>

    {{--
        The column widths ride on this header row, which is the first row with
        no colspan in it. dompdf ignores <colgroup> entirely, and
        `table-layout: fixed` would distribute the columns equally regardless of
        what is written on them — the same workaround the group info sheet uses.
    --}}
    <table class="sipp">
        <thead>
            <tr>
                <th style="width: {{ $colIssues }}pt;">Issues and Concerns Encountered</th>
                <th style="width: {{ $colSolutions }}pt;">Solutions</th>
                <th style="width: {{ $colRecommendations }}pt;">Recommendations</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['issues_concerns'] }}</td>
                    <td>{{ $row['solutions'] }}</td>
                    <td>{{ $row['recommendations'] }}</td>
                </tr>
            @empty
                <tr>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="footer">
        <tr>
            <td class="sig-label">PREPARED BY:</td>
            <td class="sig-label">CERTIFIED CORRECT:</td>
        </tr>
        <tr>
            <td>
                <span class="sig-name">{{ $meta['signatory_prepared_name'] }}</span><br>
                {{ $meta['signatory_prepared_title'] }}<br>
                <span class="sig-caption">(Name and Signature)</span>
            </td>
            <td>
                <span class="sig-name">{{ $meta['signatory_certified_name'] }}</span><br>
                {{ $meta['signatory_certified_title'] }}<br>
                <span class="sig-caption">(Name and Signature)</span>
            </td>
        </tr>
    </table>
</body>
</html>
