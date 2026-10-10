<?php

namespace App\Support;

/**
 * Which of several map pins for ONE place the group agrees on.
 *
 * Built for the GROUP Student Information Sheet's sketch box: every intern at a
 * company may pin it on their own information sheet, and they disagree — one
 * pins the gate, one the back office, one their own house by mistake. Pins are
 * continuous coordinates, so "the most frequent" means the pin with the most
 * other pins near it.
 *
 * The winner is a REAL pin (a medoid), never an average: the centroid of a
 * building pin and a stray pin a kilometre away lands in somebody's field.
 *
 * A pure static helper — a sibling of GeoDistance — so the rule is unit-tested
 * without a database.
 */
class PinConsensus
{
    /**
     * Two pins count as the same place within this distance. The DTR's default
     * geofence radius: a building, its gate and its car park.
     */
    public const RADIUS_METRES = 150.0;

    /**
     * Each pin may carry any other fields; the winner is returned whole. Order
     * matters only as the last tie-break, so pass the oldest first.
     *
     * @param  list<array{key: int|string, lat: float, lng: float}>  $pins
     * @return array{pin: array, agreeing: list<int|string>, agree_count: int, pinned_count: int, contested: bool}|null
     */
    public static function pick(array $pins, float $radiusMetres = self::RADIUS_METRES): ?array
    {
        $pins = array_values($pins);
        $total = count($pins);

        if ($total === 0) {
            return null;
        }

        $best = null;

        foreach ($pins as $i => $pin) {
            $neighbours = [];
            $spread = 0.0;

            foreach ($pins as $other) {
                $distance = GeoDistance::metresBetween(
                    (float) $pin['lat'],
                    (float) $pin['lng'],
                    (float) $other['lat'],
                    (float) $other['lng'],
                );

                if ($distance <= $radiusMetres) {
                    $neighbours[] = $other['key'];
                    $spread += $distance;
                }
            }

            $count = count($neighbours);

            // Most neighbours wins; then the most central of them (smallest
            // summed distance); then whichever came first. Strict comparisons,
            // so an exact tie keeps the earlier pin and the result never
            // depends on anything but the input.
            if ($best === null
                || $count > $best['count']
                || ($count === $best['count'] && $spread < $best['spread'] - 1e-9)) {
                $best = ['index' => $i, 'count' => $count, 'spread' => $spread, 'agreeing' => $neighbours];
            }
        }

        return [
            'pin' => $pins[$best['index']],
            'agreeing' => $best['agreeing'],
            'agree_count' => $best['count'],
            'pinned_count' => $total,
            // Not a majority: half or fewer of the pins are near the winner.
            // One pin out of one is a majority — there is nobody to disagree.
            'contested' => $total >= $best['count'] * 2,
        ];
    }
}
