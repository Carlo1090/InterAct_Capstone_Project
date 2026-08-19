{{--
    Weekly Activity Log and Time Log Summary Guide — facsimile of the MDC paper
    form (docs/reference/Weekly-Activity-Log-and-Time-Log-Summary-Guide.pdf).

    STANDALONE blade on purpose — like every other official PDF in this project,
    it does NOT extend pdf/layout.blade.php (whose font, colour and margins
    contradict the measured form). See PROJECT.md.

    dompdf notes, all load-bearing:
      * Paper is set to LETTER in the controller. dompdf defaults to A4, which
        silently narrows every column.
      * dompdf ignores <colgroup>, and ignores a width on any cell carrying a
        colspan. Both tables therefore open with a zero-height `.sizer` row of
        plain cells carrying the widths, under AUTO table layout.
      * Those widths are content-box: dompdf adds cell padding on top, so each
        is written as (target - horizontal padding).
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Weekly Activity Log and Time Log Summary Guide</title>
    <style>
        @page { margin: 36pt; }

        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10pt;
            color: #000;
            margin: 0;
        }

        /* ---- Institution header: five centred lines, all bold ---- */
        .masthead { text-align: center; margin: 0 0 18pt 0; }
        .masthead div { font-weight: bold; font-size: 11pt; line-height: 1.35; }

        .form-title {
            text-align: center;
            font-weight: bold;
            font-size: 11pt;
            margin: 0 0 10pt 0;
        }

        table { border-collapse: collapse; }
        .sizer td { padding: 0; margin: 0; border: none; height: 0; line-height: 0; font-size: 0; }

        /* ---- Info block: bordered grid, bold labels, blank fill-in cells ---- */
        table.info {
            width: 96.5%;
            margin: 0 0 20pt 0;
            table-layout: auto;
        }
        table.info td {
            border: 0.75pt solid #000;
            padding: 2pt 4pt;
            font-size: 9.5pt;
            height: 13pt;
            vertical-align: middle;
        }
        table.info td.label { font-weight: bold; }

        /* ---- Activity table: bordered grid with tall fill-in rows ---- */
        table.activity {
            width: 100%;
            table-layout: auto;
        }
        table.activity th,
        table.activity td {
            border: 0.75pt solid #000;
            padding: 3pt 4pt;
            font-size: 9pt;
            vertical-align: top;
        }
        table.activity th {
            font-weight: bold;
            font-size: 9.5pt;
            text-align: center;
            vertical-align: middle;
            height: 34pt;
        }
        table.activity td { height: 70pt; }

        .dates { font-size: 9pt; }
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

    {{-- Widths target a 521pt content column; padding (8pt) already subtracted. --}}
    <table class="info">
        <tr class="sizer">
            <td style="width: 117pt"></td>
            <td style="width: 175pt"></td>
            <td style="width: 84pt"></td>
            <td style="width: 97pt"></td>
        </tr>
        <tr>
            <td class="label">Name of Student Intern</td>
            <td>{{ $header['student_name'] }}</td>
            <td class="label">Program and Year</td>
            <td>{{ $header['program_and_year'] }}</td>
        </tr>
        <tr>
            <td class="label">Faculty Adviser</td>
            <td colspan="3">{{ $header['faculty_adviser'] }}</td>
        </tr>
        <tr>
            <td class="label">Name of Company</td>
            <td colspan="3">{{ $header['company_name'] }}</td>
        </tr>
        <tr>
            <td class="label">Name of Supervisor</td>
            <td>{{ $header['supervisor_name'] }}</td>
            <td class="label">Area Assigned</td>
            <td>{{ $log->area_assigned }}</td>
        </tr>
        <tr>
            <td class="label">Period Covered</td>
            <td>{{ $periodCovered }}</td>
            <td class="label">No. of hours</td>
            <td>{{ $hours }}</td>
        </tr>
    </table>

    {{-- Widths target a 540pt content column; padding (8pt) already subtracted. --}}
    <table class="activity">
        <tr class="sizer">
            <td style="width: 62pt"></td>
            <td style="width: 108pt"></td>
            <td style="width: 112pt"></td>
            <td style="width: 105pt"></td>
            <td style="width: 112pt"></td>
        </tr>
        <tr>
            <th>Inclusive<br>Dates</th>
            <th>Activities</th>
            <th>Document/Records</th>
            <th>Objective/s</th>
            <th>Supervisor&rsquo;s name,<br>position, and<br>Signature</th>
        </tr>

        @foreach ($rows as $row)
            <tr>
                <td class="dates">{{ $row['dates'] }}</td>
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
