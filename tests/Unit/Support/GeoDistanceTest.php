<?php

namespace Tests\Unit\Support;

use App\Support\GeoDistance;
use PHPUnit\Framework\TestCase;

class GeoDistanceTest extends TestCase
{
    public function test_the_same_point_is_zero_metres_away(): void
    {
        $this->assertSame(0.0, GeoDistance::metresBetween(9.6496, 124.1264, 9.6496, 124.1264));
    }

    /**
     * One degree of latitude is ~111.2 km everywhere on the globe (longitude
     * is not, which is the whole reason a flat approximation is avoided).
     */
    public function test_one_degree_of_latitude_is_about_111_kilometres(): void
    {
        $metres = GeoDistance::metresBetween(9.0, 124.0, 10.0, 124.0);

        $this->assertEqualsWithDelta(111195, $metres, 200);
    }

    /**
     * The realistic case: a geofence radius. Roughly 0.0009 degrees of
     * latitude is ~100m, which must read as inside a 150m fence and outside
     * a 50m one.
     */
    public function test_a_short_hop_resolves_at_geofence_scale(): void
    {
        $metres = GeoDistance::metresBetween(9.6496, 124.1264, 9.6505, 124.1264);

        $this->assertEqualsWithDelta(100, $metres, 5);
        $this->assertLessThan(150, $metres);
        $this->assertGreaterThan(50, $metres);
    }

    public function test_distance_is_symmetric(): void
    {
        $there = GeoDistance::metresBetween(9.6496, 124.1264, 9.6600, 124.1400);
        $back = GeoDistance::metresBetween(9.6600, 124.1400, 9.6496, 124.1264);

        $this->assertEqualsWithDelta($there, $back, 0.0001);
    }

    /**
     * Longitude degrees shrink toward the poles. A helper that got this wrong
     * would still pass the equator cases above.
     */
    public function test_longitude_degrees_shrink_with_latitude(): void
    {
        $atEquator = GeoDistance::metresBetween(0.0, 0.0, 0.0, 1.0);
        $atSixtyNorth = GeoDistance::metresBetween(60.0, 0.0, 60.0, 1.0);

        // cos(60 degrees) = 0.5, so the same one-degree span is half as wide.
        $this->assertEqualsWithDelta($atEquator / 2, $atSixtyNorth, 500);
    }
}
