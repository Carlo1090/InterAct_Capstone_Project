<?php

namespace Tests\Unit\Services;

use App\Services\StaticMapService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaticMapServiceTest extends TestCase
{
    private function service(): StaticMapService
    {
        return new StaticMapService;
    }

    private function enable(): void
    {
        config([
            'staticmap.enabled' => true,
            'staticmap.tile_url' => 'https://tiles.test/{z}/{x}/{y}.png',
            'staticmap.attribution' => '(c) OpenStreetMap contributors',
            'staticmap.timeout' => 5,
        ]);

        Storage::fake('local');
    }

    /** One real 256x256 PNG, so imagecreatefromstring() has something to chew on. */
    private function fakeTile(): string
    {
        $tile = imagecreatetruecolor(StaticMapService::TILE_SIZE, StaticMapService::TILE_SIZE);
        imagefill($tile, 0, 0, imagecolorallocate($tile, 220, 225, 210));

        ob_start();
        imagepng($tile);

        return (string) ob_get_clean();
    }

    public function test_web_mercator_projection_matches_the_slippy_map_standard(): void
    {
        $service = $this->service();

        // Zoom 0 is one 256px tile covering the world: (0, 0) sits dead centre.
        [$x, $y] = $service->worldPixels(0.0, 0.0, 0);
        $this->assertEqualsWithDelta(128.0, $x, 0.001);
        $this->assertEqualsWithDelta(128.0, $y, 0.001);

        // Cross-checked against the OSM wiki's own tile formula, which uses
        // log(tan + sec) where this uses log((1+sin)/(1-sin))/4 — algebraically
        // the same projection, computed a different way, so agreeing here means
        // the implementation is right rather than merely self-consistent.
        [$x, $y] = $service->worldPixels(9.6419184, 123.8550350, 17);
        $this->assertSame(110630, (int) floor($x / StaticMapService::TILE_SIZE));
        $this->assertSame(62008, (int) floor($y / StaticMapService::TILE_SIZE));
    }

    public function test_a_missing_or_impossible_pin_is_not_pinnable(): void
    {
        $service = $this->service();

        $this->assertFalse($service->isPinnable(null, null));
        $this->assertFalse($service->isPinnable('', ''));
        $this->assertFalse($service->isPinnable('not-a-number', 120));
        $this->assertFalse($service->isPinnable(91, 0));
        $this->assertFalse($service->isPinnable(0, 181));

        // Null island: what a half-initialised picker posts, never a real
        // internship company.
        $this->assertFalse($service->isPinnable(0, 0));

        $this->assertTrue($service->isPinnable(9.6419184, 123.8550350));
        $this->assertTrue($service->isPinnable('9.64', '123.85'));
    }

    public function test_zoom_is_clamped_into_the_supported_range(): void
    {
        $service = $this->service();

        $this->assertSame(StaticMapService::MIN_ZOOM, $service->clampZoom(-4));
        $this->assertSame(StaticMapService::MAX_ZOOM, $service->clampZoom(99));
        $this->assertSame(16, $service->clampZoom(16));
    }

    public function test_it_stitches_the_tiles_into_one_image_of_the_requested_size(): void
    {
        $this->enable();
        Http::fake(['tiles.test/*' => Http::response($this->fakeTile(), 200)]);

        $png = $this->service()->render(9.6419184, 123.8550350, 17, 400, 160);

        $this->assertNotNull($png);

        $size = getimagesizefromstring($png);
        $this->assertSame(400, $size[0]);
        $this->assertSame(160, $size[1]);
        $this->assertSame('image/png', $size['mime']);
    }

    public function test_the_composed_image_and_its_tiles_are_cached(): void
    {
        $this->enable();
        Http::fake(['tiles.test/*' => Http::response($this->fakeTile(), 200)]);

        $service = $this->service();
        $service->render(9.6419184, 123.8550350, 17, 400, 160);
        $firstPass = count(Http::recorded());

        $this->assertGreaterThan(0, $firstPass);

        // A second render of the same viewport must not touch the network at
        // all: a re-download of an unchanged sheet is the common case, and the
        // tile server is somebody else's free service.
        $service->render(9.6419184, 123.8550350, 17, 400, 160);
        $this->assertCount($firstPass, Http::recorded());
    }

    public function test_a_tile_server_failure_yields_no_map_rather_than_a_broken_one(): void
    {
        $this->enable();
        Http::fake(['tiles.test/*' => Http::response('nope', 500)]);

        // Null, not a grey rectangle: the blade then prints the blank box the
        // paper form has always carried, which reads as "no location pinned"
        // instead of "this application is broken".
        $this->assertNull($this->service()->render(9.6419184, 123.8550350, 17, 400, 160));
    }

    public function test_it_renders_nothing_at_all_when_switched_off(): void
    {
        $this->enable();
        config(['staticmap.enabled' => false]);
        Http::fake();

        $this->assertFalse($this->service()->isEnabled());
        $this->assertNull($this->service()->render(9.6419184, 123.8550350, 17, 400, 160));
        Http::assertNothingSent();
    }

    public function test_the_user_agent_the_tile_policy_requires_is_actually_sent(): void
    {
        $this->enable();
        config(['staticmap.user_agent' => 'InternTrack/1.0 (test)']);
        Http::fake(['tiles.test/*' => Http::response($this->fakeTile(), 200)]);

        $this->service()->render(9.6419184, 123.8550350, 17, 300, 120);

        // OpenStreetMap blocks unidentified callers outright, and the symptom
        // is a silently empty map rather than an error.
        Http::assertSent(fn ($request) => $request->header('User-Agent')[0] === 'InternTrack/1.0 (test)');
    }
}
