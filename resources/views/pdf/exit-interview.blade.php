@php
    use App\Support\ExitInterviewFormLayout as L;

    /**
     * The CABM Internship Program Student Exit Interview Form.
     *
     * THIS BLADE HOLDS NO GEOMETRY. Every position comes from
     * App\Support\ExitInterviewFormLayout::document(), which computes the whole
     * form from one horizontal grid and one vertical rhythm — see that class
     * for what was measured off the reference PDF and what was regularised, and
     * why. Adding a number here instead of there is what let the reference's
     * own spacing drift in the first place.
     *
     * dompdf positions a block by its TOP, so each computed BASELINE is
     * converted with the face's own probed ratio and every run carries
     * `line-height: font-size` and exactly ONE line.
     */
    $doc = L::document();

    $top = fn (array $el) => round($el['baseline'] - ($el['size'] * (L::BASELINE_RATIO[$el['font']] ?? 0.814)), 3);

    // A Section A blank, or a signatory's name — both are values dropped onto
    // a printed underline, trimmed to what that underline can hold.
    $fit = fn (?string $value, float $width) => L::wrap((string) $value, [$width])[0] ?? '';

    $onPage = fn (array $items, int $page) => array_values(array_filter($items, fn ($i) => $i['page'] === $page));

    // A ticked box, if that option was chosen.
    $ticked = function (string $key) use ($choices, $compliance): array {
        $marks = [];

        foreach ($choices as $question => $value) {
            if ($value !== null) {
                $marks[$question.'_choice:'.$value] = true;
            }
        }

        if ($compliance !== null) {
            $marks['compliance:'.$compliance] = true;
        }

        return $marks;
    };

    $checked = $ticked('');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Internship Program Student Exit Interview Form</title>
    <style>
        /* The layout is absolute, so the page carries no margin of its own —
           every x and y is measured from the page corner. */
        @page { margin: 0; }

        body { margin: 0; padding: 0; color: #000; font-family: Helvetica, Arial, sans-serif; }

        .page { position: relative; width: {{ L::PAGE_WIDTH }}pt; height: {{ L::PAGE_HEIGHT - 1 }}pt; overflow: hidden; }
        .page-2 { page-break-before: always; }

        /* One computed run: one line, never wrapped. */
        .t { position: absolute; margin: 0; padding: 0; white-space: nowrap; }

        /* A printed rule: an answer line, a fill-in blank, an underline. */
        .r { position: absolute; height: 0.4pt; background: #000; font-size: 0; line-height: 0; }

        /* The ☐, drawn rather than set — the reference's Segoe UI Symbol is
           proprietary and is not one of dompdf's base-14 faces, so the
           character would render as a blank or a tofu square. */
        .cb {
            position: absolute;
            width: {{ L::BOX_SIZE }}pt;
            height: {{ L::BOX_SIZE }}pt;
            border: 0.5pt solid #000;
            font-size: 0;
            line-height: 0;
        }
    </style>
</head>
<body>

@foreach ([1, 2] as $page)
    <div class="page {{ $page === 2 ? 'page-2' : '' }}">

        {{-- Printed rules: answer lines, Section A blanks, signature lines --}}
        @foreach ($onPage($doc['rules'], $page) as $rule)
            <div class="r" style="left:{{ $rule['x'] }}pt;top:{{ $rule['y'] }}pt;width:{{ $rule['w'] }}pt"></div>
        @endforeach

        {{-- Checkboxes --}}
        @foreach ($onPage($doc['boxes'], $page) as $box)
            <div class="cb" style="left:{{ $box['x'] }}pt;top:{{ $box['y'] }}pt"></div>
        @endforeach

        {{-- Their check marks, where an option was chosen --}}
        @foreach ($doc['marks'] as $key => $mark)
            @if ($mark['page'] === $page && ($checked[$key] ?? false))
                <div
                    class="t"
                    style="left:{{ $mark['x'] }}pt;top:{{ $top(['baseline' => $mark['baseline'], 'size' => L::MARK_SIZE, 'font' => 'zapf']) }}pt;font-family:ZapfDingbats;font-size:{{ L::MARK_SIZE }}pt;line-height:{{ L::MARK_SIZE }}pt"
                >{{ L::CHECK_GLYPH }}</div>
            @endif
        @endforeach

        {{-- Every printed word of the form --}}
        @foreach ($onPage($doc['texts'], $page) as $run)
            @php
                // A signatory's name is carried as a {placeholder} so the
                // layout can position it without knowing whose form this is.
                $text = $run['text'];

                if (str_starts_with($text, '{') && str_ends_with($text, '}')) {
                    $text = $form[trim($text, '{}')] ?? '';
                }
            @endphp
            <div
                class="t"
                style="
                    left:{{ $run['align'] === 'center' ? 0 : $run['x'] }}pt;
                    top:{{ $top($run) }}pt;
                    @if ($run['align'] === 'center') width:{{ $run['w'] }}pt;text-align:center;white-space:normal; @endif
                    font-size:{{ $run['size'] }}pt;
                    line-height:{{ $run['size'] }}pt;
                    @if (in_array($run['font'], ['serif', 'serifBold'], true)) font-family:Times,'Times New Roman',serif; @endif
                    @if (in_array($run['font'], ['bold', 'serifBold'], true)) font-weight:bold; @endif
                    @if ($run['color']) color:{{ $run['color'] }}; @endif
                "
            >{{ $text }}</div>
        @endforeach

        {{-- Section A's filled-in values, each trimmed to its own blank --}}
        @foreach ($doc['blanks'] as $key => $blank)
            @if ($blank['page'] === $page && ($form[$key] ?? '') !== '')
                <div
                    class="t"
                    style="left:{{ $blank['x'] }}pt;top:{{ $top(['baseline' => $blank['baseline'], 'size' => L::BODY_SIZE, 'font' => 'body']) }}pt;font-size:{{ L::BODY_SIZE }}pt;line-height:{{ L::BODY_SIZE }}pt"
                >{{ $fit($form[$key], $blank['w']) }}</div>
            @endif
        @endforeach

        {{-- The answers, already wrapped onto their own rules --}}
        @foreach ($onPage($lines, $page) as $line)
            <div
                class="t"
                style="left:{{ $line['left'] }}pt;top:{{ $top(['baseline' => $line['baseline'], 'size' => L::BODY_SIZE, 'font' => 'body']) }}pt;font-size:{{ L::BODY_SIZE }}pt;line-height:{{ L::BODY_SIZE }}pt"
            >{{ $line['text'] }}</div>
        @endforeach
    </div>
@endforeach

</body>
</html>
