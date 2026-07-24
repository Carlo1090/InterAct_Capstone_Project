<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>HTE &amp; Student Interns List</title>
    <style>
        @page { margin: 1.6cm 1.4cm; }
        body { font-family: "Times New Roman", Times, serif; font-size: 12px; color: #000; }
        .center { text-align: center; }
        .annex { text-align: right; font-weight: bold; font-size: 12px; margin-bottom: 4px; }
        .header-lines p { margin: 0; line-height: 1.35; }
        .header-lines .title { font-weight: bold; text-transform: uppercase; }
        .header-lines .ay { margin-top: 6px; font-weight: bold; }
        .meta { margin: 18px 0 12px; }
        .meta p { margin: 0 0 3px; }

        table.hte { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 4px; }
        table.hte th, table.hte td {
            border: 1px solid #000;
            padding: 5px 6px;
            font-size: 11px;
            vertical-align: top;
            text-align: left;
            word-wrap: break-word;
            overflow-wrap: break-word;
            word-break: break-word;
        }
        table.hte th { font-weight: bold; text-align: center; }
        table.hte td.col-hte { vertical-align: middle; }
        .col-hte { width: 28%; }
        .col-name { width: 24%; }
        .col-program { width: 12%; text-align: center; }
        .col-gender { width: 10%; text-align: center; }
        .col-dates { width: 26%; }
        td.col-program, td.col-gender { text-align: center; }

        table.footer { width: 100%; margin-top: 40px; }
        table.footer td { width: 50%; vertical-align: top; font-size: 12px; padding-right: 12px; }
        .sig-label { padding-bottom: 42px; }
        .sig-name { font-weight: bold; text-transform: uppercase; }
        .sig-caption { font-size: 10px; font-style: italic; }
    </style>
</head>
<body>
    <div class="annex">Annex "D"</div>
    <div class="center header-lines">
        <p class="title">REPORT ON THE</p>
        <p class="title">LIST HOST TRAINING ESTABLISHMENTS (HTEs) AND STUDENT INTERNS PARTICIPATING IN THE</p>
        <p class="title">STUDENT INTERNSHIP PROGRAM IN THE PHILIPPINES (SIPP)</p>
        <p class="ay">AY: {{ $academicYear }}</p>
    </div>

    <div class="meta">
        <p><strong>HEI:</strong> MATER DEI COLLEGE, INC.</p>
        <p><strong>ADDRESS:</strong> CABULIJAN, TUBIGON, BOHOL, PHILIPPINES</p>
    </div>

    <table class="hte">
        <thead>
            <tr>
                <th class="col-hte">PARTNER HOST TRAINING ESTABLISHMENTS</th>
                <th class="col-name">NAME OF STUDENT INTERNS</th>
                <th class="col-program">PROGRAM</th>
                <th class="col-gender">GENDER</th>
                <th class="col-dates">DATES OF DURATION OF THE INTERNSHIP</th>
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
