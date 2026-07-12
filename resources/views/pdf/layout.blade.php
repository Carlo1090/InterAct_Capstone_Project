<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'InternTrack Document')</title>
    <style>
        @page {
            margin: 70px 55px 60px 55px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            line-height: 1.6;
            color: #1e293b;
        }

        .doc-title {
            font-size: 19px;
            font-weight: bold;
            text-align: center;
            margin: 0 0 3px;
            color: #0f172a;
            letter-spacing: 0.02em;
        }

        .doc-subtitle {
            font-size: 12px;
            font-weight: normal;
            text-align: center;
            margin: 0 0 20px;
            color: #64748b;
        }

        table.meta-table {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }

        table.meta-table td {
            padding: 3px 8px 3px 0;
            font-size: 11px;
            vertical-align: top;
            color: #334155;
        }

        table.meta-table td strong {
            color: #0f172a;
        }

        .section {
            margin-bottom: 18px;
            page-break-inside: avoid;
        }

        .section-label {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            margin: 0 0 5px;
        }

        .section-body {
            font-size: 12px;
            line-height: 1.7;
            color: #1e293b;
            white-space: pre-wrap;
            margin: 0;
        }

        .day-header {
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 0.05em;
            color: #0f172a;
            margin: 14px 0 4px;
            padding-bottom: 3px;
            border-bottom: 1px solid #cbd5e1;
        }

        .day-block:first-child .day-header {
            margin-top: 0;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 11px;
            border-radius: 9px;
            font-size: 10px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .status-submitted, .status-approved {
            background: #dcfce7;
            color: #166534;
        }

        .status-missing, .status-returned {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-draft, .status-pending {
            background: #fef9c3;
            color: #854d0e;
        }

        .comment-box {
            margin-top: 18px;
            border: 1px solid #fca5a5;
            background: #fef2f2;
            padding: 10px 14px;
            border-radius: 4px;
            page-break-inside: avoid;
        }

        .comment-box .section-label {
            color: #b91c1c;
        }

        .signature-block {
            margin-top: 46px;
            page-break-inside: avoid;
        }

        .signature-line {
            border-top: 1px solid #334155;
            padding-top: 4px;
            width: 260px;
            font-size: 9px;
            color: #64748b;
        }

        .empty-note {
            font-size: 11px;
            color: #94a3b8;
            font-style: italic;
        }
    </style>
</head>
<body>
    <h1 class="doc-title">@yield('doc-title')</h1>
    <h2 class="doc-subtitle">@yield('doc-subtitle')</h2>

    @yield('content')
</body>
</html>
