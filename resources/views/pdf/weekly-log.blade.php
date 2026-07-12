{{--
    Plain typed-document weekly journal, matching the client's weekly
    reference (Weekly_journal_reference.webp): a bold "My OJT Journal
    Week N (Company)" title line, then bold uppercase day headers each
    followed by a plain narrative paragraph — nothing else (no meta
    table, status, supervisor comment, or signature block; those stay
    in the app UI only). Standalone on purpose, same reasoning as
    pdf/daily-journal-entry.blade.php. Rendered by BOTH the student
    weekly-log PDF and the supervisor review-copy PDF.
--}}
@php
    // The narrative is free text a student can edit after Weekly Bundling
    // pre-fills it, so we don't assume structure — but WeeklyBundlingService's
    // compiled format ("MONDAY\ntext") is recognized and given the day-header
    // treatment; any block that doesn't start with a weekday name still
    // renders correctly as a plain paragraph.
    $weekdayNames = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
    $narrativeBlocks = collect(preg_split('/\n{2,}/', trim((string) $narrative)))
        ->filter(fn ($block) => trim($block) !== '')
        ->map(function ($block) use ($weekdayNames) {
            $lines = explode("\n", trim($block), 2);
            $firstLine = trim($lines[0]);

            if (in_array($firstLine, $weekdayNames, true)) {
                return ['day' => $firstLine, 'text' => trim($lines[1] ?? '')];
            }

            return ['day' => null, 'text' => trim($block)];
        });
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Weekly Journal - {{ $header['student_name'] }}</title>
    <style>
        @page {
            margin: 70px 55px 60px 55px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 12px;
            line-height: 1.6;
            color: #000;
            background: #fff;
        }

        .doc-title {
            font-weight: bold;
            margin: 0 0 20px;
        }

        .day-block {
            margin: 0 0 16px;
            page-break-inside: avoid;
        }

        .day-header {
            font-weight: bold;
            margin: 0 0 2px;
        }

        .day-text {
            margin: 0;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
    <p class="doc-title">My OJT Journal Week {{ $weekNumber }}@if ($header['company_name'] ?? null) ({{ $header['company_name'] }})@endif</p>

    @foreach ($narrativeBlocks as $block)
        <div class="day-block">
            @if ($block['day'])
                <p class="day-header">{{ $block['day'] }}</p>
            @endif
            <p class="day-text">{{ $block['text'] }}</p>
        </div>
    @endforeach
</body>
</html>
