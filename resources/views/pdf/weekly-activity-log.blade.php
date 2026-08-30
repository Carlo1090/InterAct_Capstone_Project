{{--
    Weekly Activity Log and Time Log Summary Guide — facsimile of the MDC paper
    form (docs/reference/Weekly-Activity-Log-and-Time-Log-Summary-Guide.pdf).

    STANDALONE blade on purpose — like every other official PDF in this project,
    it does NOT extend pdf/layout.blade.php (whose font, colour and margins
    contradict the measured form). See PROJECT.md.

    EVERY NUMBER BELOW WAS EXTRACTED FROM THE REFERENCE PDF'S OWN VECTOR
    GEOMETRY (its stroked table rules and text baselines), not estimated by eye.
    Do not "tidy" them. The reference, in points on a 612x792 US Letter page:

        page         content column x 56.8 -> 559.8  (503.0pt wide, both tables)
        info table   rules at x 56.8 | 177.0 | 357.7 | 453.5 | 559.8
                     rows  at y 646.5 632.7 618.7 604.7 590.8 577.1  (~13.9pt each)
        activity     rules at x 56.8 | 120.25 | 226.75 | 333.0 | 453.5 | 559.8
                     header row 40.75pt, body rows filling down to y 67.5
        type         Calibri Bold 11pt throughout — masthead, title, every field
                     label and every column heading
        rules        0.5pt black

    dompdf notes, all load-bearing:
      * Paper is set to LETTER in the controller. dompdf defaults to A4, which
        silently narrows every column.
      * dompdf ignores <colgroup>, and ignores a width on any cell carrying a
        colspan, and `table-layout: fixed` distributes columns equally
        regardless. The widths therefore ride on the first row of each table
        that has no colspan in it — the info table's first data row, and the
        activity table's header row.
        There is deliberately NO separate zero-height `.sizer` row any more: a
        `.sizer td` rule (0-1-1) loses on specificity to `table.info td`
        (0-1-2), so the sizer rows inherited the full border, padding and height
        of a real row and PRINTED as an empty leading row in both tables.
      * Those widths are content-box: dompdf adds cell padding on top, so each
        is written as (target - 10pt of horizontal padding).
      * The font is Carlito, registered from resources/fonts by
        BuildsWeeklyActivityLogPdf. It is metric-compatible with the reference's
        Calibri and is OFL-licensed, so it can ship in the repo and in the
        Docker image; Helvetica stays as the fallback if registration fails.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Weekly Activity Log and Time Log Summary Guide</title>
    <style>
        /* Reference margins: the content column runs x 56.8 -> 559.8. */
        @page {
            margin-top: 55.4pt;
            margin-right: 52.2pt;
            margin-bottom: 54pt;
            margin-left: 56.8pt;
        }

        body {
            font-family: carlito, Helvetica, Arial, sans-serif;
            font-size: 11pt;
            color: #000;
            margin: 0;
        }

        /* ---- Institution header: three centred bold lines, 14.5pt apart ----
           EVERY line-height in this file is written PRE-DIVIDED, and that is
           not a typo. dompdf does not use `line-height` as the line box height:
           FrameDecorator\Text::get_margin_height() returns
           (line_height / font_size) * fontHeight, and fontHeight is
           (winAscent + winDescent) / unitsPerEm * FONT_HEIGHT_RATIO — for
           Carlito that is (1950 + 550) / 2048 * 1.1 = 1.3428em. So a plain
           `line-height: 14.5pt` renders a 19.47pt line and every heading, row
           and table comes out a third too tall (which is what pushed the blank
           form onto a second page). Divide the target by 1.3428:
           14.5 / 1.3428 = 10.8pt. A unitless value is NOT a way out — it is
           multiplied by font-size first and then hits the same factor.
           The 9.4pt indent is not decoration: the reference centres its heading
           block on x=313.0, which is 4.7pt right of the content column's own
           centre (308.3). */
        .masthead { text-align: center; margin: 0; padding-left: 9.4pt; }
        .masthead div { font-weight: bold; font-size: 11pt; line-height: 10.8pt; }

        /* 37pt from the last masthead baseline; 12.5pt down to the info table. */
        .form-title {
            text-align: center;
            font-weight: bold;
            font-size: 11pt;
            line-height: 10.8pt;
            padding-left: 9.4pt;
            margin: 22.5pt 0 9.7pt 0;
        }

        table { border-collapse: collapse; }

        /* ---- Info block: bordered grid, bold labels, blank fill-in cells ---- */
        table.info {
            width: 503pt;
            margin: 0 0 22.7pt 0;
            table-layout: auto;
        }
        table.info td {
            border: 0.5pt solid #000;
            padding: 0 5pt;
            font-size: 11pt;
            line-height: 9.98pt;
            height: 13.4pt;
            vertical-align: middle;
        }
        table.info td.label { font-weight: bold; }
        /* The pre-printed labels are 11pt; a typed value is dropped a size so a
           long company or coordinator name still fits its cell on one line. */
        table.info td.value { font-size: 9.5pt; }

        /* ---- Activity table: bordered grid with tall fill-in rows ---- */
        table.activity {
            width: 503pt;
            table-layout: auto;
        }
        table.activity th,
        table.activity td {
            border: 0.5pt solid #000;
        }
        table.activity th {
            font-weight: bold;
            font-size: 11pt;
            line-height: 9.99pt;
            text-align: center;
            /* TOP, not middle: on the reference every heading's first line
               shares one baseline with "Inclusive". */
            vertical-align: top;
            height: 40.25pt;
            padding: 0 5pt;
        }
        /* Height is content-box in dompdf and, unlike line-height, is NOT
           rescaled — so each row's printed pitch is (height + 4pt padding +
           the 0.5pt rule). The per-row heights are emitted inline below,
           because the reference's five pre-printed rows are NOT uniform
           (94.5 94.5 94.5 81.25 81.0), which is what lands its last rule on
           y=67.5. This is a minimum: a row with more text in it still grows. */
        table.activity td {
            font-size: 9pt;
            line-height: 8.56pt;
            vertical-align: top;
            padding: 2pt 5pt;
        }

        .sup-name { font-weight: normal; }
        .sup-pos { font-size: 8pt; }
    </style>
</head>
<body>

    <div class="masthead">
        <div>Mater Dei College</div>
        <div>{{ $header['department_line'] }}</div>
        <div>{{ $header['unit_line'] }}</div>
    </div>

    <div class="form-title">Weekly Activity Log and Time Log Summary Guide</div>

    {{-- Column widths ride on this first row; padding (10pt) already subtracted. --}}
    <table class="info">
        <tr>
            <td class="label" style="width: 109.7pt">Name of Student Intern</td>
            <td class="value" style="width: 170.2pt">{{ $header['student_name'] }}</td>
            <td class="label" style="width: 85.3pt">Program and Year</td>
            <td class="value" style="width: 95.8pt">{{ $header['program_and_year'] }}</td>
        </tr>
        <tr>
            <td class="label">Faculty Adviser</td>
            <td class="value" colspan="3">{{ $header['faculty_adviser'] }}</td>
        </tr>
        <tr>
            <td class="label">Name of Company</td>
            <td class="value" colspan="3">{{ $header['company_name'] }}</td>
        </tr>
        <tr>
            <td class="label">Name of Supervisor</td>
            <td class="value">{{ $header['supervisor_name'] }}</td>
            <td class="label">Area Assigned</td>
            <td class="value">{{ $log->area_assigned }}</td>
        </tr>
        <tr>
            <td class="label">Period Covered</td>
            <td class="value">{{ $periodCovered }}</td>
            <td class="label">No. of hours</td>
            <td class="value">{{ $hours }}</td>
        </tr>
    </table>

    {{-- Column widths ride on the header row; padding (10pt) already subtracted. --}}
    <table class="activity">
        <tr>
            <th style="width: 52.95pt">Inclusive<br>Dates</th>
            <th style="width: 96pt">Activities</th>
            <th style="width: 95.75pt">Document/Records</th>
            <th style="width: 110pt">Objective/s</th>
            <th style="width: 95.8pt">Supervisor&rsquo;s name,<br>position, and<br>Signature</th>
        </tr>

        @php
            // The reference's own five pre-printed row pitches, less the 4pt of
            // vertical padding and the 0.5pt rule each one carries. A sixth or
            // later row is not on the paper form at all, so it reuses the last.
            $rowHeights = [90.0, 90.0, 90.0, 76.75, 76.5];
        @endphp
        @foreach ($rows as $index => $row)
            <tr>
                <td style="height: {{ $rowHeights[$index] ?? end($rowHeights) }}pt">{{ $row['dates'] }}</td>
                <td>{{ $row['activities'] }}</td>
                <td>{{ $row['documents_records'] }}</td>
                <td>{{ $row['objectives'] }}</td>
                <td>
                    @if ($row['supervisor_name'])
                        <div class="sup-name">{{ $row['supervisor_name'] }}</div>
                    @endif
                    @if ($row['supervisor_position'])
                        <div class="sup-pos">{{ $row['supervisor_position'] }}</div>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>

</body>
</html>
