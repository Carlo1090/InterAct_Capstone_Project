<?php

namespace Tests\Unit\Support;

use App\Support\PinConsensus;
use PHPUnit\Framework\TestCase;

class PinConsensusTest extends TestCase
{
    private const LAT = 9.6475;

    private const LNG = 123.8540;

    /** Metres per degree of latitude at GeoDistance's Earth radius. */
    private const METRES_PER_DEGREE = 111194.93;

    /** A pin $metres due north of the base point. */
    private function pin(int|string $key, float $metres = 0): array
    {
        return ['key' => $key, 'lat' => self::LAT + $metres / self::METRES_PER_DEGREE, 'lng' => self::LNG];
    }

    public function test_no_pins_has_no_consensus(): void
    {
        $this->assertNull(PinConsensus::pick([]));
    }

    public function test_a_single_pin_is_a_majority_of_one(): void
    {
        $result = PinConsensus::pick([$this->pin('a')]);

        $this->assertSame('a', $result['pin']['key']);
        $this->assertSame(1, $result['agree_count']);
        $this->assertSame(1, $result['pinned_count']);
        $this->assertFalse($result['contested']);
    }

    public function test_the_pins_that_agree_outvote_a_stray_one_listed_first(): void
    {
        $result = PinConsensus::pick([
            $this->pin('stray', 1500),
            $this->pin('gate'),
            $this->pin('office', 40),
        ]);

        $this->assertSame('gate', $result['pin']['key']);
        $this->assertSame(['gate', 'office'], $result['agreeing']);
        $this->assertSame(2, $result['agree_count']);
        $this->assertSame(3, $result['pinned_count']);
        $this->assertFalse($result['contested']);
    }

    public function test_the_winner_is_the_most_central_real_pin_not_an_average(): void
    {
        $result = PinConsensus::pick([$this->pin('south'), $this->pin('middle', 60), $this->pin('north', 120)]);

        $this->assertSame('middle', $result['pin']['key']);
        $this->assertSame(3, $result['agree_count']);
    }

    public function test_two_pins_that_disagree_are_contested_and_the_earlier_one_wins(): void
    {
        $result = PinConsensus::pick([$this->pin('first'), $this->pin('second', 2000)]);

        $this->assertSame('first', $result['pin']['key']);
        $this->assertSame(1, $result['agree_count']);
        $this->assertTrue($result['contested']);
    }

    public function test_half_and_half_is_contested_and_the_tighter_pair_wins(): void
    {
        $result = PinConsensus::pick([
            $this->pin('loose-a'),
            $this->pin('loose-b', 100),
            $this->pin('tight-a', 3000),
            $this->pin('tight-b', 3020),
        ]);

        $this->assertSame('tight-a', $result['pin']['key']);
        $this->assertSame(2, $result['agree_count']);
        $this->assertTrue($result['contested']);
    }

    public function test_the_radius_is_150_metres(): void
    {
        $this->assertSame(2, PinConsensus::pick([$this->pin('a'), $this->pin('b', 149)])['agree_count']);
        $this->assertSame(1, PinConsensus::pick([$this->pin('a'), $this->pin('b', 151)])['agree_count']);
    }
}
