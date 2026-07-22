<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Student Information Sheet (Group)</title>
    <style>
        @page { margin: 1.4cm 1.6cm; }
        body { font-family: "Times New Roman", Times, serif; font-size: 12px; color: #000; }

        .head { text-align: center; }
        .head img { height: 70px; }
        .head .college { font-family: "Times New Roman", serif; font-size: 20px; font-weight: bold; margin: 2px 0 0; }
        .head .place { font-size: 12px; margin: 0; }
        .head .dept { font-size: 13px; font-weight: bold; color: #17255e; margin: 8px 0 0; }
        .head .prog { font-size: 13px; font-weight: bold; margin: 10px 0 0; }
        .head .sheet { font-size: 13px; font-weight: bold; margin: 0; }

        .logo-placeholder {
            display: inline-block; width: 70px; height: 70px; line-height: 70px;
            border: 1px dashed #999; font-size: 8px; color: #999; text-align: center;
        }

        .bar {
            background: #17255e; color: #fff; font-weight: bold; font-size: 12px;
            text-transform: uppercase; text-align: center; padding: 4px 0; margin: 16px 0 8px;
            letter-spacing: 0.5px;
        }

        table.roster { width: 100%; border-collapse: collapse; font-size: 10px; }
        table.roster th, table.roster td { border: 1px solid #000; padding: 4px 5px; }
        table.roster th { font-weight: bold; text-align: center; vertical-align: middle; }
        table.roster td { height: 16px; vertical-align: middle; }
        table.roster td.num { text-align: center; width: 4%; }
        table.roster td.mi { text-align: center; }
        table.roster .empty { color: #000; }

        table.fields { width: 100%; border-collapse: collapse; }
        table.fields td { padding: 5px 6px; vertical-align: bottom; font-size: 12px; }
        td.label { font-weight: bold; white-space: nowrap; width: 1%; }
        td.value { border-bottom: 1px solid #000; }

        .sketch-label { font-weight: bold; font-size: 12px; margin: 14px 0 4px; }
        .sketch-box { border: 1px solid #000; width: 100%; height: 35mm; }
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
        @if ($logo)
            <img src="{{ $logo }}" alt="Mater Dei College">
        @else
            <span class="logo-placeholder">MDC LOGO<br>(drop logo at<br>public/images/mdc-logo.png)</span>
        @endif
        <p class="college">Mater Dei College</p>
        <p class="place">Tubigon, Bohol</p>
        <p class="dept">{{ $departmentLine }}</p>
        <p class="prog">STUDENT INTERNSHIP PROGRAM</p>
        <p class="sheet">Student Information Sheet</p>
    </div>

    <div class="bar">Student Trainee Information</div>
    <table class="roster">
        <thead>
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
        </thead>
        <tbody>
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
        </tbody>
    </table>

    <div class="bar">Internship Company Information</div>
    <table class="fields">
        <tr>
            <td class="label">Name of Company</td>
            <td class="value" colspan="3">{{ $company['host_company'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Company Address</td>
            <td class="value" colspan="3">{{ $company['company_address'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Complete Name of Official<br>Company Signatory (for MOA)</td>
            <td class="value">{{ $company['company_signatory_moa'] ?? '' }}</td>
            <td class="label">Office Designation / Position</td>
            <td class="value">{{ $company['office_designation'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Name of Supervisor / Office Head</td>
            <td class="value">{{ $company['supervisor_name'] ?? '' }}</td>
            <td class="label">Contact Number</td>
            <td class="value">{{ $company['supervisor_contact'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Intern's Duty Schedule</td>
            <td class="value">{{ $company['intern_duty_schedule'] ?? '' }}</td>
            <td class="label">Start of Internship Duty</td>
            <td class="value">{{ $fmtDate($company['ojt_start_date'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="label">Area Assigned</td>
            <td class="value">{{ $company['area_assigned'] ?? '' }}</td>
            <td class="label">Estimated Date to<br>Finish Internship</td>
            <td class="value">{{ $fmtDate($company['ojt_end_date'] ?? null) }}</td>
        </tr>
    </table>

    <p class="sketch-label">Sketch of Internship Company Location:</p>
    <div class="sketch-box"></div>
</body>
</html>
