@extends('pdf.layout')

@section('title', 'Weekly Log - ' . $header['student_name'])
@section('doc-title', 'Weekly Activity Narrative')
@section('doc-subtitle', \Carbon\Carbon::parse($weekStart)->toFormattedDateString() . ' to ' . \Carbon\Carbon::parse($weekEnd)->toFormattedDateString())

@section('extra-style')
    <style>
        {{-- Explicit, named override for this document — not an inherited
             coincidence that today's shared default also happens to be 12px. --}}
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 12px;
        }

        .section-body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 12px;
        }
    </style>
@endsection

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

@section('content')
    <table class="meta-table">
        <tr>
            <td style="width: 50%"><strong>Student Name:</strong> {{ $header['student_name'] }}</td>
            <td style="width: 50%"><strong>Program:</strong> {{ $header['program'] ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Host Company:</strong> {{ $header['company_name'] ?? '—' }}</td>
            <td><strong>Status:</strong> <span class="status-badge status-{{ $status }}">{{ $status }}</span></td>
        </tr>
        @if ($header['supervisor_name'] ?? null)
            <tr>
                <td><strong>Company Supervisor:</strong> {{ $header['supervisor_name'] }}</td>
                <td>@if ($submittedAt)<strong>Submitted:</strong> {{ \Carbon\Carbon::parse($submittedAt)->toDayDateTimeString() }}@endif</td>
            </tr>
        @endif
    </table>

    <div class="section">
        <p class="section-label">Weekly Narrative</p>

        @forelse ($narrativeBlocks as $block)
            <div class="day-block">
                @if ($block['day'])
                    <p class="day-header">{{ $block['day'] }}</p>
                @endif
                <p class="section-body">{{ $block['text'] }}</p>
            </div>
        @empty
            <p class="empty-note">No narrative was written for this week.</p>
        @endforelse
    </div>

    @if ($supervisorComment)
        <div class="comment-box">
            <p class="section-label">Supervisor's Comment</p>
            <p class="section-body">{{ $supervisorComment }}</p>
        </div>
    @endif

    <div class="signature-block">
        <p class="signature-line">{{ $header['supervisor_name'] ?? 'Company Supervisor' }} — Signature</p>
    </div>
@endsection
