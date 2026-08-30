<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders the pinned company location as a flat image for the Student
 * Information Sheet PDF's "Sketch of Internship Company Location" box.
 *
 * dompdf cannot run JavaScript, so an interactive map is not an option inside
 * the document — the picker lives in the SPA and only the coordinates are
 * stored. This service turns those coordinates back into a picture at PDF
 * time, the same call BuildsInfoSheetPdf already makes for the MDC logo: a
 * base64 data URI, which dompdf renders reliably.
 *
 * THE WHOLE THING IS BEST-EFFORT AND MUST STAY THAT WAY. It reaches out over
 * the network from inside a PDF download, so every failure path — no tiles, a
 * slow tile server, GD missing, a nonsense coordinate — returns null and the
 * blade prints the blank box the form has always had. A student must never be
 * unable to download their own information sheet because a tile server was
 * having a bad day.
 */
class StaticMapService
{
    /** Standard slippy-map tile edge, in pixels. */
    public const TILE_SIZE = 256;

    public const MIN_ZOOM = 3;

    public const MAX_ZOOM = 19;

    /**
     * Hard ceiling on tiles per render. A 50mm box needs ~15; this exists only
     * so a bad width/height can never turn one PDF download into hundreds of
     * requests at somebody else's tile server.
     */
    private const MAX_TILES = 40;

    private const CACHE_DIR = 'static-maps';

    private const TILE_CACHE_DIR = 'static-maps/tiles';

    /**
     * How many tiles to fetch at once.
     *
     * Sequential fetching made a cold render ~4s, which is a long time to hold
     * a PDF download open. Six is not an arbitrary "make it faster" number: it
     * is exactly what a browser opens per host, so a map this page draws
     * costs the tile server no more than the same map drawn in Leaflet would.
     */
    private const FETCH_CONCURRENCY = 6;

    /**
     * The collapsed preview on the info-sheet form, in pixels. Fixed
     * server-side rather than taken from the request: a caller-controlled
     * canvas size is a way to make us fetch arbitrarily many tiles. The ratio
     * matches the printed 90mm box, so the preview IS what will print.
     */
    public const PREVIEW_WIDTH = 640;

    public const PREVIEW_HEIGHT = 324;

    /**
     * A composed map as a base64 data URI, ready for an <img src>.
     */
    public function dataUri(float $lat, float $lng, int $zoom, int $width, int $height): ?string
    {
        $png = $this->render($lat, $lng, $zoom, $width, $height);

        return $png === null ? null : 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * The composed PNG for a viewport centred on the point, or null if it could
     * not be produced for any reason at all.
     */
    public function render(float $lat, float $lng, int $zoom, int $width, int $height): ?string
    {
        if (! $this->isEnabled() || ! $this->isPinnable($lat, $lng)) {
            return null;
        }

        $zoom = $this->clampZoom($zoom);

        // The composed image is cached, not just the tiles: two students at the
        // same company pin nearly the same point, and a re-download of an
        // unchanged sheet should never touch the network at all.
        $key = self::CACHE_DIR.'/'.sha1(implode('|', [
            round($lat, 6), round($lng, 6), $zoom, $width, $height, $this->tileUrl(),
        ])).'.png';

        try {
            $disk = Storage::disk('local');

            if ($disk->exists($key)) {
                return $disk->get($key);
            }
        } catch (Throwable) {
            // An unreadable cache is not a reason to skip the map.
            $disk = null;
        }

        try {
            $png = $this->compose($lat, $lng, $zoom, $width, $height);
        } catch (Throwable) {
            return null;
        }

        if ($png !== null && isset($disk)) {
            try {
                $disk->put($key, $png);
            } catch (Throwable) {
                // The cache is an optimisation; losing it costs nothing here.
            }
        }

        return $png;
    }

    public function isEnabled(): bool
    {
        return (bool) config('staticmap.enabled') && function_exists('imagecreatetruecolor');
    }

    /**
     * A usable pin: real numbers, in range, and not the (0, 0) "null island" a
     * half-initialised picker produces.
     */
    public function isPinnable(mixed $lat, mixed $lng): bool
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return false;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if (abs($lat) > 90 || abs($lng) > 180) {
            return false;
        }

        return ! ($lat === 0.0 && $lng === 0.0);
    }

    public function attribution(): string
    {
        return (string) config('staticmap.attribution');
    }

    public function clampZoom(mixed $zoom): int
    {
        $zoom = is_numeric($zoom) ? (int) $zoom : (int) config('staticmap.default_center.zoom');

        return max(self::MIN_ZOOM, min(self::MAX_ZOOM, $zoom));
    }

    /**
     * Global pixel coordinates of a lat/lng at a zoom level (Web Mercator).
     * Public so the projection can be pinned by a unit test with no network.
     */
    public function worldPixels(float $lat, float $lng, int $zoom): array
    {
        $span = self::TILE_SIZE * (2 ** $zoom);

        // Clamp to the Mercator limit — the projection diverges at the poles.
        $lat = max(-85.05112878, min(85.05112878, $lat));
        $sin = sin(deg2rad($lat));

        return [
            ($lng + 180) / 360 * $span,
            (0.5 - log((1 + $sin) / (1 - $sin)) / (4 * M_PI)) * $span,
        ];
    }

    /**
     * Fetch every tile covering the viewport and paint them onto one canvas.
     *
     * Returns null when NOT ONE tile could be fetched: a uniformly grey box is
     * a worse answer than the blank box the paper form has always carried, and
     * it would read as "the map is broken" rather than "no location pinned".
     */
    private function compose(float $lat, float $lng, int $zoom, int $width, int $height): ?string
    {
        [$centreX, $centreY] = $this->worldPixels($lat, $lng, $zoom);

        $left = $centreX - $width / 2;
        $top = $centreY - $height / 2;

        $firstX = (int) floor($left / self::TILE_SIZE);
        $lastX = (int) floor(($left + $width) / self::TILE_SIZE);
        $firstY = (int) floor($top / self::TILE_SIZE);
        $lastY = (int) floor(($top + $height) / self::TILE_SIZE);

        if (($lastX - $firstX + 1) * ($lastY - $firstY + 1) > self::MAX_TILES) {
            return null;
        }

        $wanted = [];

        for ($x = $firstX; $x <= $lastX; $x++) {
            for ($y = $firstY; $y <= $lastY; $y++) {
                $span = 2 ** $zoom;

                if ($x < 0 || $y < 0 || $x >= $span || $y >= $span) {
                    continue;
                }

                $wanted[] = ['x' => $x, 'y' => $y];
            }
        }

        $images = $this->tiles($zoom, $wanted);

        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 233, 233, 229));

        $painted = 0;

        foreach ($wanted as $position) {
            $raw = $images["{$position['x']}/{$position['y']}"] ?? null;

            if ($raw === null) {
                continue;
            }

            $tile = @imagecreatefromstring($raw);

            if (! $tile instanceof GdImage) {
                continue;
            }

            imagecopy(
                $canvas,
                $tile,
                (int) round($position['x'] * self::TILE_SIZE - $left),
                (int) round($position['y'] * self::TILE_SIZE - $top),
                0,
                0,
                self::TILE_SIZE,
                self::TILE_SIZE
            );

            $painted++;
        }

        if ($painted === 0) {
            return null;
        }

        $this->drawMarker($canvas, $width, $height);
        $this->drawAttribution($canvas, $width, $height);

        // Quantise to a 256-colour palette WITHOUT dithering. Street-map tiles
        // are flat artwork drawn from a small palette to begin with, so this is
        // visually indistinguishable from truecolour at print size while
        // cutting the PNG to about a third (measured: 134KB -> 43KB on a
        // 1009x283 render, which is a third off every info sheet PDF).
        // Dithering is deliberately off: it costs most of the saving and
        // speckles the tiles' own label text.
        @imagetruecolortopalette($canvas, false, 256);

        ob_start();
        imagepng($canvas, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * Every requested tile, keyed "x/y" — cache first, then whatever is left
     * fetched in parallel.
     *
     * Caching tiles rather than only the composed image is what keeps this
     * neighbourly: every student placed at the same company pins within a few
     * hundred metres of their classmates, so the second sheet onward is served
     * entirely from disk.
     *
     * @param  array<int, array{x: int, y: int}>  $wanted
     * @return array<string, string>
     */
    private function tiles(int $zoom, array $wanted): array
    {
        $disk = null;

        try {
            $disk = Storage::disk('local');
        } catch (Throwable) {
            // An unreadable cache costs speed, never correctness.
        }

        $found = [];
        $missing = [];

        foreach ($wanted as $position) {
            $key = "{$position['x']}/{$position['y']}";
            $path = self::TILE_CACHE_DIR."/{$zoom}/{$position['x']}/{$position['y']}.png";

            try {
                if ($disk !== null && $disk->exists($path)) {
                    $found[$key] = $disk->get($path);

                    continue;
                }
            } catch (Throwable) {
                // Fall through and fetch it.
            }

            $missing[$key] = $position;
        }

        foreach (array_chunk($missing, self::FETCH_CONCURRENCY, true) as $batch) {
            foreach ($this->fetchBatch($zoom, $batch) as $key => $body) {
                $found[$key] = $body;

                if ($disk === null) {
                    continue;
                }

                [$x, $y] = explode('/', $key);

                try {
                    $disk->put(self::TILE_CACHE_DIR."/{$zoom}/{$x}/{$y}.png", $body);
                } catch (Throwable) {
                    // Fine — we still have the bytes for this render.
                }
            }
        }

        return $found;
    }

    /**
     * One concurrent batch of tile requests.
     *
     * @param  array<string, array{x: int, y: int}>  $batch
     * @return array<string, string>
     */
    private function fetchBatch(int $zoom, array $batch): array
    {
        $headers = [
            // Required by the OpenStreetMap tile usage policy. Without an
            // identifying User-Agent the request is blocked, and the symptom is
            // a silently empty map rather than an error.
            'User-Agent' => (string) config('staticmap.user_agent'),
        ];
        $timeout = (int) config('staticmap.timeout');
        $template = $this->tileUrl();

        // Pool keys cannot contain a slash, so the "x/y" key is flattened here
        // and put back on the way out.
        $keys = [];

        try {
            $responses = Http::pool(function ($pool) use ($batch, $headers, $timeout, $template, $zoom, &$keys) {
                $requests = [];

                foreach ($batch as $key => $position) {
                    $alias = 't'.$position['x'].'_'.$position['y'];
                    $keys[$alias] = $key;

                    $requests[] = $pool->as($alias)
                        ->withHeaders($headers)
                        ->timeout($timeout)
                        ->get(str_replace(
                            ['{z}', '{x}', '{y}'],
                            [(string) $zoom, (string) $position['x'], (string) $position['y']],
                            $template
                        ));
                }

                return $requests;
            });
        } catch (Throwable) {
            return [];
        }

        $out = [];

        foreach ($responses as $alias => $response) {
            if (! isset($keys[$alias]) || $response instanceof Throwable) {
                continue;
            }

            if (! method_exists($response, 'successful') || ! $response->successful()) {
                continue;
            }

            $body = $response->body();

            // A tile server answering 200 with an error page is not a tile.
            if ($body === '' || strlen($body) < 100) {
                continue;
            }

            $out[$keys[$alias]] = $body;
        }

        return $out;
    }

    /**
     * A teardrop pin on the exact centre of the viewport.
     *
     * Drawn into a 4x sprite and resampled down, because GD's polygon and
     * ellipse fills are not anti-aliased and a hard-edged pin looks like a
     * rendering fault next to the tiles' own smooth artwork.
     */
    private function drawMarker(GdImage $canvas, int $width, int $height): void
    {
        $scale = 4;
        $radius = max(7, (int) round(min($width, $height) * 0.042));
        $pinWidth = $radius * 2 + 2;
        $pinHeight = (int) round($radius * 3.2);

        $sprite = imagecreatetruecolor($pinWidth * $scale, $pinHeight * $scale);
        imagealphablending($sprite, false);
        imagesavealpha($sprite, true);
        imagefill($sprite, 0, 0, imagecolorallocatealpha($sprite, 0, 0, 0, 127));
        imagealphablending($sprite, true);

        // blue-900, the token the rest of the app uses for a neutral primary.
        $blue = imagecolorallocate($sprite, 30, 58, 138);
        $white = imagecolorallocate($sprite, 255, 255, 255);

        $cx = (int) round($pinWidth * $scale / 2);
        $headY = $radius * $scale;
        $r = $radius * $scale;

        imagefilledpolygon($sprite, [
            $cx - (int) round($r * 0.72), $headY + (int) round($r * 0.55),
            $cx + (int) round($r * 0.72), $headY + (int) round($r * 0.55),
            $cx, $pinHeight * $scale - 1,
        ], $blue);
        imagefilledellipse($sprite, $cx, $headY, $r * 2, $r * 2, $blue);
        imagefilledellipse($sprite, $cx, $headY, (int) round($r * 0.8), (int) round($r * 0.8), $white);

        $destX = (int) round($width / 2 - $pinWidth / 2);
        // The point of the pin, not its centre, marks the spot.
        $destY = (int) round($height / 2 - $pinHeight);

        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $sprite, $destX, $destY, 0, 0, $pinWidth, $pinHeight, $pinWidth * $scale, $pinHeight * $scale);
    }

    /**
     * The tile licence requires visible attribution. It is burned into the
     * image (rather than only printed beside the box) so the credit cannot be
     * separated from the map by a crop or a copy-paste.
     */
    private function drawAttribution(GdImage $canvas, int $width, int $height): void
    {
        $text = $this->attribution();

        if ($text === '') {
            return;
        }

        $font = base_path('resources/fonts/Carlito-Regular.ttf');
        $ink = imagecolorallocate($canvas, 55, 55, 55);
        // Sized off the WIDTH, not the height. Both canvases this service
        // draws are the full width of what they sit in and vary only in how
        // tall the box is, so height would make the credit grow every time the
        // printed box got taller — at 90mm it rendered twice the size it had
        // at 50mm and read as a watermark across the map.
        $size = max(9, (int) round($width * 0.013));

        if (is_file($font) && function_exists('imagettftext')) {
            $box = @imagettfbbox($size, 0, $font, $text);

            if ($box !== false) {
                $textWidth = $box[2] - $box[0];
                $textHeight = $box[1] - $box[7];
                $pad = 4;

                $plate = imagecolorallocatealpha($canvas, 255, 255, 255, 40);
                imagefilledrectangle(
                    $canvas,
                    $width - $textWidth - $pad * 3,
                    $height - $textHeight - $pad * 2,
                    $width,
                    $height,
                    $plate
                );
                imagettftext($canvas, $size, 0, $width - $textWidth - $pad * 2, $height - $pad, $ink, $font, $text);

                return;
            }
        }

        // GD's built-in bitmap font, for a runtime without freetype.
        imagestring($canvas, 2, max(2, $width - 6 * strlen($text) - 4), $height - 16, $text, $ink);
    }

    private function tileUrl(): string
    {
        return (string) config('staticmap.tile_url');
    }
}
