<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Weekly Activity Log</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 16px; text-align: center; margin-bottom: 4px; }
        h2 { font-size: 11px; text-align: center; margin-top: 0; margin-bottom: 16px; font-weight: normal; color: #555; }
        table.header-table { width: 100%; margin-bottom: 12px; }
        table.header-table td { padding: 2px 4px; font-size: 11px; }
        table.entries { width: 100%; border-collapse: collapse; }
        table.entries th, table.entries td { border: 1px solid #333; padding: 6px; font-size: 10px; vertical-align: top; text-align: left; }
        table.entries th { background: #f2f2f2; }
        .signature-line { margin-top: 10px; border-top: 1px solid #333; padding-top: 2px; font-size: 9px; color: #555; }
    </style>
</head>
<body>
    <h1>Weekly Activity Log</h1>
    <h2>Week of {{ optional($log->week_start)->toDateString() }} to {{ optional($log->week_end)->toDateString() }}</h2>

    <table class="header-table">
        <tr>
            <td><strong>Student Name:</strong> {{ $header['student_name'] }}</td>
            <td><strong>Program / Year Level:</strong> {{ $header['program'] }} {{ $header['year_level'] }}</td>
        </tr>
        <tr>
            <td><strong>Internship Coordinator:</strong> {{ $header['coordinator_name'] }}</td>
            <td><strong>Host Company:</strong> {{ $header['company_name'] }}</td>
        </tr>
        <tr>
            <td><strong>Area Assigned:</strong> {{ $log->area_assigned }}</td>
            <td><strong>No. of Hours:</strong> {{ $log->no_of_hours }}</td>
        </tr>
    </table>

    <table class="entries">
        <thead>
            <tr>
                <th style="width: 14%">Inclusive Dates</th>
                <th style="width: 26%">Activities</th>
                <th style="width: 20%">Document/Records</th>
                <th style="width: 20%">Objective/s</th>
                <th style="width: 20%">Supervisor Name / Position / Signature</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($log->entries as $entry)
                <tr>
                    <td>{{ optional($entry->inclusive_date_start)->toDateString() }} - {{ optional($entry->inclusive_date_end)->toDateString() }}</td>
                    <td>{{ $entry->activities }}</td>
                    <td>{{ $entry->documents_records }}</td>
                    <td>{{ $entry->objectives }}</td>
                    <td>
                        {{ $entry->supervisor_name ?? $header['supervisor_name'] }}<br>
                        {{ $entry->supervisor_position }}
                        <div class="signature-line">Signature</div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">No entries recorded for this week.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
