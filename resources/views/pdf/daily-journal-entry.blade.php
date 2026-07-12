@extends('pdf.layout')

@section('title', 'Daily Journal Entry - ' . $header['student_name'])
@section('doc-title', 'Daily Journal Entry')
@section('doc-subtitle', \Carbon\Carbon::parse($entryDate)->translatedFormat('l, F j, Y'))

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
    </table>

    @forelse ($sections as $section)
        <div class="section">
            <p class="section-label">{{ $section['label'] }}</p>
            <p class="section-body">{{ trim((string) ($content[$section['key']] ?? '')) !== '' ? $content[$section['key']] : '—' }}</p>
        </div>
    @empty
        <p class="empty-note">No journal template sections were configured for this entry.</p>
    @endforelse
@endsection
