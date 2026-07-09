{{-- Placeholder — full official layout built in Part 3. --}}
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Annual SIPP Report</title></head>
<body>
<h1>Annual SIPP Report — AY: {{ $academicYear }}</h1>
<table border="1">
    <thead>
        <tr><th>Issues and Concerns Encountered</th><th>Solutions</th><th>Recommendations</th></tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row['issues_concerns'] }}</td>
                <td>{{ $row['solutions'] }}</td>
                <td>{{ $row['recommendations'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
</body>
</html>
