<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Static map rendering
    |--------------------------------------------------------------------------
    |
    | Backs the "Sketch of Internship Company Location" box on the Student
    | Information Sheet PDF: the student drops a pin, and the server stitches
    | the raster tiles covering that point into one image.
    |
    | OpenStreetMap is the default deliberately. Google Maps (including its
    | Static Maps API) requires a billing account with a card on file even
    | inside the free credit, and this project has a hard zero-cost /
    | no-credit-card constraint (see PROJECT.md's Deployment section). OSM
    | raster tiles need no key, no account and no card.
    |
    | Switching providers is one env var: any {z}/{x}/{y} raster endpoint works,
    | so a paid or keyed tile source can be dropped in without a code change.
    |
    */

    'enabled' => env('STATIC_MAP_ENABLED', true),

    'tile_url' => env('STATIC_MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),

    /*
    | The OpenStreetMap tile usage policy REQUIRES a User-Agent that identifies
    | the application. A generic or absent one gets blocked, and the symptom is
    | an empty map with no error. Keep this specific.
    */
    'user_agent' => env('STATIC_MAP_USER_AGENT', 'InternTrack/1.0 (MDC SIPP OJT monitoring; capstone project)'),

    /*
    | Shown on the rendered image and under the box on the PDF. The tile licence
    | requires visible attribution — do not remove it.
    */
    'attribution' => env('STATIC_MAP_ATTRIBUTION', '(c) OpenStreetMap contributors'),

    /*
    | Per-tile HTTP timeout, seconds. The whole render is bounded by this times
    | the tile count, so keep it short: a slow tile server must never hold a PDF
    | download open. A timeout is not fatal — the box falls back to blank.
    */
    'timeout' => (int) env('STATIC_MAP_TIMEOUT', 6),

    /*
    | Where the map picker opens before the student has pinned anything. Mater
    | Dei College, Tubigon, Bohol — the institution on the form's own masthead.
    */
    'default_center' => [
        'lat' => (float) env('STATIC_MAP_DEFAULT_LAT', 9.9483),
        'lng' => (float) env('STATIC_MAP_DEFAULT_LNG', 123.9622),
        'zoom' => (int) env('STATIC_MAP_DEFAULT_ZOOM', 13),
    ],

    /*
    | Address search endpoint (student/location-search). Nominatim is free and
    | keyless, but its policy caps use at ~1 request/second and forbids
    | autocomplete-as-you-type — which is why the UI searches only on an
    | explicit submit, the route is throttled, and results are cached.
    */
    'geocoder_url' => env('STATIC_MAP_GEOCODER_URL', 'https://nominatim.openstreetmap.org/search'),

    'geocoder_country_codes' => env('STATIC_MAP_GEOCODER_COUNTRIES', 'ph'),

];
