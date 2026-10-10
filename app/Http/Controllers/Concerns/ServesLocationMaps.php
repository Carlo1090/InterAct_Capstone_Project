<?php

namespace App\Http\Controllers\Concerns;

use App\Services\StaticMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three endpoints behind CompanyLocationPicker.vue — options, a preview
 * image, and address search — shared by the student's info sheet and the
 * coordinator's group sheet, so the map either of them pins on is the same map
 * that prints.
 */
trait ServesLocationMaps
{
    /**
     * What the location picker needs to draw itself.
     *
     * Served rather than hardcoded in the SPA so the picker and the PDF can
     * never disagree about which map they are showing — both read
     * config/staticmap.php, exactly the reasoning behind lib/dtr.ts on the
     * clock-in side.
     *
     * Deliberately does NOT seed from company_geofences. Those coordinates are
     * where a company's QR clock-in fence is anchored, and handing every
     * student the precise location of every company's fence would lower the
     * cost of spoofing a punch — the one thing that scheme's honesty rests on.
     * The picker opens on the college instead and the user moves the pin.
     */
    protected function locationOptionsResponse(): JsonResponse
    {
        $maps = app(StaticMapService::class);

        return response()->json([
            'enabled' => $maps->isEnabled(),
            'tile_url' => config('staticmap.tile_url'),
            'attribution' => $maps->attribution(),
            'default_center' => config('staticmap.default_center'),
            'min_zoom' => StaticMapService::MIN_ZOOM,
            'max_zoom' => StaticMapService::MAX_ZOOM,
            'search_enabled' => (bool) config('staticmap.geocoder_url'),
        ]);
    }

    /**
     * The pinned location as a small image, for a form's collapsed state.
     *
     * This is what makes the map cheap: a form does not mount a map library and
     * a dozen live tiles just to say "you pinned this place". It shows ONE
     * cached PNG — and because the caller renders it at its printed box's own
     * aspect ratio, it is literally a preview of what the PDF will contain
     * rather than an approximation of it.
     *
     * The canvas size comes from the CALLER'S CODE, never from the request. A
     * caller-controlled width and height is a way to make the server fetch
     * arbitrarily many tiles from somebody else's free service on demand.
     */
    protected function locationPreviewResponse(Request $request, int $width, int $height): Response
    {
        $maps = app(StaticMapService::class);

        $lat = $request->query('lat');
        $lng = $request->query('lng');

        abort_unless($maps->isPinnable($lat, $lng), 404);

        $png = $maps->render((float) $lat, (float) $lng, $maps->clampZoom($request->query('zoom')), $width, $height);

        // 404 rather than a placeholder image: the <img> then simply fails and
        // the form falls back to showing the coordinates as text, instead of
        // presenting a grey rectangle as if it were the location.
        abort_if($png === null, 404);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            // A pin does not move unless someone moves it, and the URL carries
            // the coordinates — so the browser never needs to ask twice.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Look an address up so nobody has to drag the map across a province to
     * find the company.
     *
     * Nominatim is free and keyless, but its usage policy caps callers at about
     * one request a second and explicitly forbids autocomplete-as-you-type.
     * Three things keep us inside it: the UI only searches on an explicit
     * submit, the route is throttled, and every answer is cached for a day —
     * a cohort of interns all looking up the same handful of local companies
     * therefore makes a handful of requests, not hundreds.
     */
    protected function locationSearchResponse(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 3) {
            return response()->json(['results' => []]);
        }

        $endpoint = (string) config('staticmap.geocoder_url');

        if ($endpoint === '') {
            return response()->json(['results' => [], 'unavailable' => true]);
        }

        $results = Cache::remember(
            'staticmap:geocode:'.sha1(mb_strtolower($query)),
            now()->addDay(),
            function () use ($endpoint, $query) {
                try {
                    $response = Http::withHeaders([
                        // Required by the Nominatim usage policy; an unidentified
                        // caller gets blocked outright.
                        'User-Agent' => (string) config('staticmap.user_agent'),
                    ])->timeout((int) config('staticmap.timeout'))->get($endpoint, [
                        'q' => $query,
                        'format' => 'jsonv2',
                        'limit' => 5,
                        'countrycodes' => config('staticmap.geocoder_country_codes'),
                    ]);
                } catch (\Throwable) {
                    return null;
                }

                if (! $response->successful()) {
                    return null;
                }

                return collect($response->json())
                    ->map(fn ($row) => [
                        'label' => $row['display_name'] ?? null,
                        'lat' => isset($row['lat']) ? (float) $row['lat'] : null,
                        'lng' => isset($row['lon']) ? (float) $row['lon'] : null,
                    ])
                    ->filter(fn ($row) => $row['label'] && $row['lat'] !== null && $row['lng'] !== null)
                    ->values()
                    ->all();
            }
        );

        // A null answer is a lookup that failed, not a search with no matches —
        // don't cache the failure, and tell the UI to offer the manual pin.
        if ($results === null) {
            Cache::forget('staticmap:geocode:'.sha1(mb_strtolower($query)));

            return response()->json(['results' => [], 'unavailable' => true]);
        }

        return response()->json(['results' => $results]);
    }
}
