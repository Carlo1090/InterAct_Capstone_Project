<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Student Information Sheet (Group)</title>
    {{--
        Geometry, type and colour below are measured from the client reference
        (docs/"Student Information Sheet (Group) (1) (3).pdf"), not invented:

          page        US Letter, 612 x 792 pt
          type        Arial-BoldMT for every label/heading, ArialMT for the
                      row numbers and the sketch caption
          bars        #1F3864 (0.122 0.22 0.392 rg) with white 10pt text
          roster      x 50.4 -> 567.8 (517.4pt), col edges 74.2 / 156.5 /
                      242.1 / 263.3 / 312.9 / 370.1 / 469.0
          company     x 29.4 -> 582.4 (553.0pt), col edges 164.25 / 347.1 / 490.35
          borders     0.4pt black hairlines
          sketch box  518.4 x 189.65 pt, inset 24.1pt from the content edge
    --}}
    <style>
        /* Left/right margin matches the company table's own edges (29.4pt), so
           that table fills the content box exactly; the narrower roster table
           is inset from it rather than the page being re-margined. */
        @page { margin: 36pt 29.4pt 30pt 29.4pt; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt;
            color: #000;
            margin: 0;
        }

        /* ---- Header block: Arial Bold 10pt, centred, 12.4pt leading ----
           No logo, deliberately: the client reference contains no image at all,
           and its absence is what lets a full 12-intern roster, the company
           block and the sketch box share one page. Adding one pushed the sheet
           onto a second page. The individual sheet keeps its logo. --- */
        .head { text-align: center; }
        .head p { font-weight: bold; font-size: 10pt; line-height: 12.4pt; margin: 0; }
        .head .gap { margin-top: 12.4pt; }

        /* ---- Section bars ----
           Deliberately rendered OUTSIDE their tables. dompdf sizes columns from
           the first row, so a colspan bar sitting there collapsed every column
           to an equal share and ignored the specified widths. --- */
        .bar {
            background: #1F3864; color: #fff; font-weight: bold; font-size: 10pt;
            text-align: center; height: 11.6pt; line-height: 11.6pt; padding: 0;
        }
        .bar.roster-bar { width: 517.4pt; margin-left: 21pt; }
        .bar.company-bar { width: 553pt; margin-top: 20.6pt; }

        table { border-collapse: collapse; }
        td, th { border: 0.4pt solid #000; padding: 0 3pt; }

        /* dompdf resolves fixed-layout column widths from the FIRST row only,
           and ignores both <colgroup> and any width on a cell that carries a
           rowspan/colspan — every real first row here has one or the other, so
           each table opens with an invisible zero-height row whose eight (or
           four) plain cells carry the measured widths. */
        tr.sizer td {
            height: 0; padding: 0; border: none;
            font-size: 0; line-height: 0;
        }

        /* ---- Band 1: intern roster ----
           Fixed layout so the measured column widths hold even when a cell's
           text is wider than its column (e.g. "BSA 4th Year" in the 49.6pt
           Program & Year column); auto layout grows the column instead. --- */
        table.roster { width: 517.4pt; margin-left: 21pt; }
        table.roster th {
            font-weight: bold; font-size: 9pt; text-align: center;
            vertical-align: top; padding: 1pt 2pt; line-height: 10.4pt;
        }
        /* Data is set a point smaller than the labels, padded tighter, and
           never wrapped. The form is blank so it dictates no data size, but it
           does dictate a 12.1pt row — and a wrapped cell (a contact number is
           just wider than the reference's 57.2pt column at 9pt) silently made
           every row 19.9pt, which pushed a full 12-intern roster onto a second
           page. nowrap keeps one line per intern, as on the paper form. */
        table.roster td {
            font-size: 8pt; height: 12.1pt; line-height: 12.1pt;
            vertical-align: middle; padding: 0 1pt;
            white-space: nowrap; overflow: hidden;
        }
        table.roster td.num { font-weight: normal; font-size: 10pt; text-align: center; }
        table.roster td.mi { text-align: center; }
        /* The bar spans the table's full width as its first row. */
        table.roster td.bar, table.company td.bar { border-color: #1F3864; }

        /* ---- Band 2: coordinator-typed company block ---- */
        table.company { width: 553pt; }
        table.company td { font-size: 9pt; vertical-align: middle; }
        table.company td.label { font-weight: bold; }
        /* The signatory label is set 1pt smaller in the reference so it fits. */
        table.company td.label.tight { font-size: 8pt; }
        /* Row heights measured off the reference's horizontal border runs. */
        table.company tr.r21 td { height: 21.2pt; }
        table.company tr.r32 td { height: 31.6pt; }

        /* ---- Sketch ---- */
        .sketch-label {
            font-size: 10pt; font-weight: normal;
            margin: 20.9pt 0 0 42.6pt;
        }
        .sketch-box {
            border: 0.4pt solid #000; width: 518.4pt; height: 189.65pt;
            margin: 9.5pt 0 0 24.1pt;
        }

        {{-- The "Page N of M" footer is stamped by the canvas API in
             BuildsGroupInfoSheetPdf, not styled here: dompdf's counter(pages)
             resolves to 0 in CSS because the total is not known until the whole
             document has been laid out. --}}
    </style>
</head>
<body>
    @php
        $fmtDate = function ($value) {
            if (! $value) return '';
            try { return \Illuminate\Support\Carbon::parse($value)->format('F j, Y'); }
            catch (\Throwable $e) { return (string) $value; }
        };
    @endphp

    <div class="head">
        <p>Mater Dei College</p>
        <p>Tubigon, Bohol</p>
        <p class="gap">{{ $departmentLine }}</p>
        <p class="gap">STUDENT INTERNSHIP PROGRAM</p>
        <p>Student Information Sheet</p>
    </div>

    <div class="bar roster-bar">STUDENT TRAINEE INFORMATION</div>
    <table class="roster">
        {{-- Column edges measured off the reference: 50.4 / 74.2 / 156.5 /
             242.1 / 263.3 / 312.9 / 370.1 / 469.0 / 567.8. Widths are given in
             pt inline: a bare width="N" attribute is read as PIXELS by dompdf
             and comes out three-quarters of the intended size. --}}
        {{-- Widths are content-box: dompdf adds each cell's 3pt of horizontal
             padding on top, so every value here is its target column width
             minus 3pt (23.8 -> 20.8, 82.3 -> 79.3, and so on). --}}
        <tr class="sizer">
            <td style="width: 20.8pt"></td>
            <td style="width: 79.3pt"></td>
            <td style="width: 82.6pt"></td>
            <td style="width: 18.2pt"></td>
            <td style="width: 46.6pt"></td>
            <td style="width: 54.2pt"></td>
            <td style="width: 95.9pt"></td>
            <td style="width: 95.8pt"></td>
        </tr>
        <tr>
            <th rowspan="2"></th>
            <th rowspan="2">Family Name</th>
            <th rowspan="2">First Name</th>
            <th rowspan="2">MI</th>
            <th rowspan="2">Program<br>&amp; Year</th>
            <th rowspan="2">Contact<br>no.</th>
            <th colspan="2">Parent's/Guardian's</th>
        </tr>
        <tr>
            <th>Name</th>
            <th>Contact no.</th>
        </tr>
        @foreach ($rows as $index => $row)
            <tr>
                <td class="num">{{ $index + 1 }}</td>
                <td>{{ $row['last_name'] ?? '' }}</td>
                <td>{{ $row['first_name'] ?? '' }}</td>
                <td class="mi">{{ $row['middle_initial'] ?? '' }}</td>
                <td>{{ $row['program_year'] ?? '' }}</td>
                <td>{{ $row['contact_number'] ?? '' }}</td>
                <td>{{ $row['parent_guardian_name'] ?? '' }}</td>
                <td>{{ $row['parent_guardian_contact'] ?? '' }}</td>
            </tr>
        @endforeach
    </table>

    <div class="bar company-bar">INTERNSHIP COMPANY INFORMATION</div>
    <table class="company">
        {{-- Column edges measured off the reference: 29.4 / 164.25 / 347.1 /
             490.35 / 582.4. Declared here rather than on the first row's cells
             because that row is a colspan and fixed layout would split it into
             three equal parts instead of the document's real proportions. --}}
        {{-- Content-box again, less this table's 6pt of horizontal padding. --}}
        <tr class="sizer">
            <td style="width: 128.85pt"></td>
            <td style="width: 176.85pt"></td>
            <td style="width: 137.25pt"></td>
            <td style="width: 86.05pt"></td>
        </tr>
        <tr class="r21">
            <td class="label">Name of Company</td>
            <td colspan="3">{{ $company['host_company'] ?? '' }}</td>
        </tr>
        <tr class="r21">
            <td class="label">Company Address</td>
            <td colspan="3">{{ $company['company_address'] ?? '' }}</td>
        </tr>
        <tr class="r21">
            <td class="label tight">Complete Name of Official<br>Company Signatory (for MOA)</td>
            <td>{{ $company['company_signatory_moa'] ?? '' }}</td>
            <td class="label">Office Designation/<br>Position</td>
            <td>{{ $company['office_designation'] ?? '' }}</td>
        </tr>
        <tr class="r32">
            <td class="label">Name of Supervisor/<br>Office Head</td>
            <td>{{ $company['supervisor_name'] ?? '' }}</td>
            <td class="label">Contact Number</td>
            <td>{{ $company['supervisor_contact'] ?? '' }}</td>
        </tr>
        <tr class="r21">
            <td class="label">Intern's Duty Schedule</td>
            <td>{{ $company['intern_duty_schedule'] ?? '' }}</td>
            <td class="label">Start of Internship Duty:</td>
            <td>{{ $fmtDate($company['ojt_start_date'] ?? null) }}</td>
        </tr>
        <tr class="r21">
            <td class="label">Area Assigned</td>
            <td>{{ $company['area_assigned'] ?? '' }}</td>
            <td class="label">Estimated date to<br>finish internship:</td>
            <td>{{ $fmtDate($company['ojt_end_date'] ?? null) }}</td>
        </tr>
    </table>

    <p class="sketch-label">Sketch of Internship Company Location:</p>
    <div class="sketch-box"></div>
</body>
</html>
