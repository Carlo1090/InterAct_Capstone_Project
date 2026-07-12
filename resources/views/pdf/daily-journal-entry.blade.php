{{--
    Plain typed-document daily journal, matching the client's reference
    (Daily_Journal_ref.jpg): uppercase student name left / program right on
    one line, an "Nth Day (MM-DD-YYYY)" label, then flowing narrative
    paragraphs. Standalone on purpose — pdf/layout.blade.php's doc-title/
    meta-table/status chrome is exactly what this document must not have.
--}}
@php
    $dailyAccomplishmentText = trim((string) ($content['daily_accomplishment'] ?? ''));
    $otherFilledSections = collect($sections)
        ->reject(fn ($section) => $section['key'] === 'daily_accomplishment')
        ->filter(fn ($section) => trim((string) ($content[$section['key']] ?? '')) !== '');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Daily Journal - {{ $header['student_name'] }}</title>
    <style>
        @page {
            margin: 70px 55px 60px 55px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 16px;
            line-height: 1.6;
            color: #000;
            background: #fff;
        }

        table.header-line {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 26px;
        }

        table.header-line td {
            padding: 0;
            vertical-align: top;
        }

        .day-label {
            margin: 0 0 16px;
        }

        .body-paragraph {
            margin: 0 0 14px;
            text-align: justify;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
    <table class="header-line">
        <tr>
            <td>{{ mb_strtoupper($header['student_name']) }}</td>
            <td style="text-align: right;">{{ $header['program'] ?? '' }}</td>
        </tr>
    </table>

    <p class="day-label">{{ $dayLabel }} ({{ \Carbon\Carbon::parse($entryDate)->format('m-d-Y') }})</p>

    @if ($dailyAccomplishmentText !== '')
        <p class="body-paragraph">{{ $dailyAccomplishmentText }}</p>
    @endif

    @foreach ($otherFilledSections as $section)
        <p class="body-paragraph"><strong>{{ $section['label'] }}:</strong> {{ trim((string) $content[$section['key']]) }}</p>
    @endforeach
</body>
</html>
