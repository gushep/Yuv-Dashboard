<?php

namespace Tests\Unit;

use App\Services\ThroughputForecast;
use PHPUnit\Framework\TestCase;

class ThroughputForecastTest extends TestCase
{
    public function test_constant_throughput_gives_a_certain_outcome(): void
    {
        $result = (new ThroughputForecast)->simulate(array_fill(0, 12, 2), remainingDays: 3, remainingItems: 6, remainingCommitted: 7, runs: 500);

        $this->assertTrue($result['available']);
        $this->assertSame(6, $result['p50']);
        $this->assertSame(6, $result['p85']);
        $this->assertSame(100.0, $result['probabilityAll']);
        $this->assertSame(0.0, $result['probabilityCommitted']);
        $this->assertSame(2.0, $result['mean']);
    }

    public function test_same_seed_reproduces_the_same_forecast(): void
    {
        $samples = [0, 1, 3, 2, 0, 4, 1, 2, 5, 0, 1, 3];
        $forecast = new ThroughputForecast;

        $this->assertSame($forecast->simulate($samples, 5, 10, 8, seed: 42), $forecast->simulate($samples, 5, 10, 8, seed: 42));
    }

    public function test_short_or_empty_history_makes_the_forecast_unavailable(): void
    {
        $forecast = new ThroughputForecast;

        $this->assertFalse($forecast->simulate([1, 2, 3], 5, 4, 4)['available']);
        $this->assertFalse($forecast->simulate(array_fill(0, 20, 0), 5, 4, 4)['available']);
    }
}
