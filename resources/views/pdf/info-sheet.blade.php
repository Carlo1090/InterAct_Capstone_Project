<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Student Information Sheet</title>
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

        table.fields { width: 100%; border-collapse: collapse; }
        table.fields td { padding: 5px 6px; vertical-align: bottom; font-size: 12px; }
        td.label { font-weight: bold; white-space: nowrap; width: 1%; }
        td.value { border-bottom: 1px solid #000; }
        td.value.empty { color: #000; }
        .spacer td { padding: 3px 0; border: none; }
    </style>
</head>
<body>
    @php
        $fmtDate = function ($value) {
            if (! $value) return '';
            try { return \Illuminate\Support\Carbon::parse($value)->format('F j, Y'); }
            catch (\Throwable $e) { return (string) $value; }
        };
        $programYear = trim(($academic['program_course'] ?? '').' '.($academic['year_level'] ?? ''));
    @endphp

    <div class="head">
        @if ($logo)
            <img src="{{ $logo }}" alt="Mater Dei College">
        @else
            <span class="logo-placeholder">MDC LOGO<br>(drop logo at<br>public/images/mdc-logo.png)</span>
        @endif
        <p class="college">Mater Dei College</p>
        <p class="place">Tubigon, Bohol</p>
        <p class="dept">College of Accountancy, Business and Management</p>
        <p class="prog">STUDENT INTERNSHIP PROGRAM</p>
        <p class="sheet">Student Information Sheet</p>
    </div>

    <div class="bar">Student Trainee Information</div>
    <table class="fields">
        <tr>
            <td class="label">Family Name</td>
            <td class="value">{{ $personal['last_name'] ?? '' }}</td>
            <td class="label">Program &amp; Year</td>
            <td class="value">{{ $programYear }}</td>
        </tr>
        <tr>
            <td class="label">First Name</td>
            <td class="value">{{ $personal['first_name'] ?? '' }}</td>
            <td class="label">Contact No.</td>
            <td class="value">{{ $personal['contact_number'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Middle Name</td>
            <td class="value">{{ $personal['middle_name'] ?? '' }}</td>
            <td class="label"></td>
            <td class="value" style="border: none;"></td>
        </tr>
        <tr>
            <td class="label">Parent's / Guardian's Name</td>
            <td class="value">{{ $personal['parent_guardian_name'] ?? '' }}</td>
            <td class="label">Contact No.</td>
            <td class="value">{{ $personal['parent_guardian_contact'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Internship Coordinator</td>
            <td class="value" colspan="3">{{ $academic['internship_coordinator'] ?? '' }}</td>
        </tr>
    </table>

    <div class="bar">Internship Company Information</div>
    <table class="fields">
        <tr>
            <td class="label">Name of Company</td>
            <td class="value" colspan="3">{{ $ojt['host_company'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Company Address</td>
            <td class="value" colspan="3">{{ $ojt['company_address'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Complete Name of Official<br>Company Signatory (for MOA)</td>
            <td class="value">{{ $ojt['company_signatory_moa'] ?? '' }}</td>
            <td class="label">Office Designation / Position</td>
            <td class="value">{{ $ojt['office_designation'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Name of Supervisor / Office Head</td>
            <td class="value">{{ $ojt['supervisor_name'] ?? '' }}</td>
            <td class="label">Contact Number</td>
            <td class="value">{{ $ojt['supervisor_contact'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Intern's Duty Schedule</td>
            <td class="value">{{ $ojt['intern_duty_schedule'] ?? '' }}</td>
            <td class="label">Area Assigned</td>
            <td class="value">{{ $ojt['area_assigned'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Start of Internship Duty</td>
            <td class="value">{{ $fmtDate($ojt['ojt_start_date'] ?? null) }}</td>
            <td class="label">Estimated Date to<br>Finish Internship</td>
            <td class="value">{{ $fmtDate($ojt['ojt_end_date'] ?? null) }}</td>
        </tr>
    </table>
</body>
</html>
