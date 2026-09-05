@php
    use App\Support\SippAnnexLayout as L;

    // Content-box widths: dompdf adds cell padding on top of a written width.
    [$colHte, $colName, $colProgram, $colGender, $colDates] = L::contentWidths(L::ANNEX_D_COLUMNS);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>HTE &amp; Student Interns List</title>
    {{--
        Annex "D", measured from docs/reference/ANNEX D - SIPP REPORT (1).docx.
        Same sheet as Annex C — 8.5in x 13in LANDSCAPE, set on the Pdf instance
        in HteReportController::pdf(), which previously asked for A4 landscape
        (842 x 595pt against the reference's 935.55 x 612.1).

        Every number lives in App\Support\SippAnnexLayout and is the reference's
        own twip figure divided by 20. Do not round them.
    --}}
    <style>
        @page { margin: {{ L::MARGIN }}pt; }

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

        table.hte {
            width: {{ L::tableWidth(L::ANNEX_D_COLUMNS) }}pt;
            border-collapse: collapse;
            margin-top: 4pt;
        }

        /* Horizontal padding is the reference's 108 twips; see the Annex C blade. */
        table.hte th,
        table.hte td {
            border: {{ L::RULE }}pt solid #000;
            padding: 3pt {{ L::CELL_PAD_X }}pt;
            font-size: {{ L::BODY_PT }}pt;
            vertical-align: top;
            text-align: left;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        table.hte th { font-weight: bold; text-align: center; }

        /*
         * The merged Host Establishment cell centres against the run of interns
         * it spans, which is what makes the merge read as a grouping rather than
         * a gap.
         */
        table.hte td.col-hte { vertical-align: middle; }
        td.col-program, td.col-gender { text-align: center; }

        table.footer { width: 100%; margin-top: 30pt; }
        table.footer td { width: 50%; vertical-align: top; padding-right: 12pt; }
        .sig-label { padding-bottom: 40pt; font-weight: bold; }
        .sig-name { font-weight: bold; text-transform: uppercase; }
        .sig-caption { font-size: 10pt; font-style: italic; }
    </style>
</head>
<body>
    <div class="annex">Annex &ldquo;D&rdquo;</div>

    <div class="header-lines">
        <p>REPORT ON THE</p>
        <p>LIST HOST TRAINING ESTABLISHMENTS (HTEs) AND STUDENT INTERNS PARTICIPATING IN THE</p>
        <p>STUDENT INTERNSHIP PROGRAM IN THE PHILIPPINES (SIPP)</p>
        <p class="ay">AY: {{ $academicYear }}</p>
    </div>

    <div class="meta">
        <p><strong>HEI:</strong> MATER DEI COLLEGE, INC.</p>
        <p><strong>ADDRESS:</strong> CABULIJAN, TUBIGON, BOHOL, PHILIPPINES</p>
    </div>

    {{--
        Widths ride on the header row — the only row guaranteed to carry no
        rowspan. dompdf ignores a width written on a cell that spans, which is
        exactly what the Host Establishment column does further down.
    --}}
    <table class="hte">
        <thead>
            <tr>
                <th style="width: {{ $colHte }}pt;">PARTNER HOST TRAINING ESTABLISHMENTS</th>
                <th style="width: {{ $colName }}pt;">NAME OF STUDENT INTERNS</th>
                <th style="width: {{ $colProgram }}pt;">PROGRAM</th>
                <th style="width: {{ $colGender }}pt;">GENDER</th>
                <th style="width: {{ $colDates }}pt;">DATES OF DURATION OF THE INTERNSHIP</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @if ($row['show_host_establishment'] ?? true)
                        <td class="col-hte" rowspan="{{ $row['host_establishment_rowspan'] ?? 1 }}">{{ $row['host_establishment'] }}</td>
                    @endif
                    <td class="col-name">{{ $row['student_name'] }}</td>
                    <td class="col-program">{{ $row['program'] }}</td>
                    <td class="col-gender">{{ $row['gender'] }}</td>
                    <td class="col-dates">{{ $row['duration'] }}</td>
                </tr>
            @empty
                <tr>
                    <td class="col-hte">&nbsp;</td>
                    <td class="col-name">&nbsp;</td>
                    <td class="col-program">&nbsp;</td>
                    <td class="col-gender">&nbsp;</td>
                    <td class="col-dates">&nbsp;</td>
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
