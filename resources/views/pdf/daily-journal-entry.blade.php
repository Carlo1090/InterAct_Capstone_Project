@extends('pdf.layout')

@section('title', 'Daily Journal Entry - ' . $header['student_name'])
@section('doc-title', 'Daily Journal Entry')
@section('doc-subtitle', \Carbon\Carbon::parse($entryDate)->translatedFormat('l, F j, Y'))

@section('extra-style')
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 16px;
        }

        .section-body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 16px;
        }
    </style>
@endsection

@php
    $dailyAccomplishmentText = trim((string) ($content['daily_accomplishment'] ?? ''));
    $otherFilledSections = collect($sections)
        ->reject(fn ($section) => $section['key'] === 'daily_accomplishment')
        ->filter(fn ($section) => trim((string) ($content[$section['key']] ?? '')) !== '');
@endphp

@section('content')
    <table class="meta-table">
        <tr>
            <td style="width: 50%"><strong>Student Name:</strong> {{ $header['student_name'] }}</td>
            <td style="width: 50%"><strong>Program:</strong> {{ $header['program'] ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Host Company:</strong> {{ $header['company_name'] ?? '—' }}</td>
            <td></td>
        </tr>
    </table>

    @if ($dailyAccomplishmentText !== '')
        <div class="section">
            <p class="section-body">{{ $dailyAccomplishmentText }}</p>
        </div>
    @endif

    @foreach ($otherFilledSections as $section)
        <div class="section">
            <p class="section-label">{{ $section['label'] }}</p>
            <p class="section-body">{{ $content[$section['key']] }}</p>
        </div>
    @endforeach

    @if ($dailyAccomplishmentText === '' && $otherFilledSections->isEmpty())
        <p class="empty-note">No content was recorded for this entry.</p>
    @endif
@endsection
