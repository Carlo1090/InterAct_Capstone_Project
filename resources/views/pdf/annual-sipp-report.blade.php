<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Annual SIPP Report</title>
    <style>
        @page { margin: 2cm 1.8cm; }
        body { font-family: "Times New Roman", Times, serif; font-size: 12px; color: #000; }
        .center { text-align: center; }
        .annex { text-align: right; font-weight: bold; font-size: 12px; margin-bottom: 4px; }
        .header-lines p { margin: 0; line-height: 1.35; }
        .header-lines .title { font-weight: bold; text-transform: uppercase; }
        .header-lines .ay { margin-top: 6px; font-weight: bold; }
        .meta { margin: 20px 0 14px; }
        .meta p { margin: 0 0 3px; }

        table.sipp { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 4px; }
        table.sipp th, table.sipp td {
            border: 1px solid #000;
            padding: 6px 8px;
            font-size: 11px;
            vertical-align: top;
            text-align: left;
            width: 33.33%;
            word-wrap: break-word;
            overflow-wrap: break-word;
            word-break: break-word;
        }
        table.sipp th { font-weight: bold; text-align: center; background: #f2f2f2; }

        table.footer { width: 100%; margin-top: 40px; }
        table.footer td { width: 50%; vertical-align: top; font-size: 12px; padding-right: 12px; }
        .sig-label { padding-bottom: 42px; }
        .sig-name { font-weight: bold; text-transform: uppercase; }
        .sig-caption { font-size: 10px; font-style: italic; }
    </style>
</head>
<body>
    <div class="annex">Annex "C"</div>
    <div class="center header-lines">
        <p class="title">ANNUAL REPORT IN THE IMPLEMENTATION OF</p>
        <p class="title">STUDENT INTERNSHIP PROGRAM IN THE PHILIPPINES (SIPP)</p>
        <p class="ay">AY: {{ $academicYear }}</p>
    </div>

    <div class="meta">
        <p><strong>HEI:</strong> MATER DEI COLLEGE, INC.</p>
        <p><strong>ADDRESS:</strong> CABULIJAN, TUBIGON, BOHOL, PHILIPPINES</p>
        <p><strong>DEGREE PROGRAM:</strong> {{ $meta['heading'] }}</p>
    </div>

    <table class="sipp">
        <thead>
            <tr>
                <th>Issues and Concerns Encountered</th>
                <th>Solutions</th>
                <th>Recommendations</th>
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
