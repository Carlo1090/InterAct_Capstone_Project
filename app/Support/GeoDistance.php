<?php

namespace App\Support;

/**
 * Great-circle distance, for deciding whether a clock-in punch was taken
 * inside a company's geofence.
 *
 * A pure static helper — a sibling of BatchWorkingDays and ReminderSchedule —
 * so the geofence rule can be unit-tested without touching the database.
 */
class GeoDistance
{
    /** Mean Earth radius in metres (IUGG). */
    private const EARTH_RADIUS_METRES = 6371008.8;

    /**
     * Haversine distance between two WGS84 points, in metres.
     *
     * Haversine rather than the flat-earth equirectangular approximation:
     * at the scale of a geofence either is accurate to well under a metre,
     * but haversine has no latitude-dependent error term to reason about,
     * and this runs once per punch so the cost is irrelevant.
     */
    public static function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lng2 - $lng1);

        $a = sin($deltaPhi / 2) ** 2
            + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METRES * asin(min(1.0, sqrt($a)));
    }
}
