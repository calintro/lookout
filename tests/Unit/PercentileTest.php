<?php

use Lookout\Services\Performance;
use NightOwl\Support\DDSketchHistogram;
use NightOwl\Support\QueryHistogram;

/**
 * Durations 1..100 ms in microseconds: the true p95 is 95 ms.
 *
 * @return list<int>
 */
function nightowlSampleDurations(): array
{
    return array_map(fn (int $ms): int => $ms * 1000, range(1, 100));
}

it('reads p95 from a packed DDSketch within the sketch error', function () {
    $counts = [];
    foreach (nightowlSampleDurations() as $duration) {
        DDSketchHistogram::add($counts, $duration);
    }

    $p95 = Performance::percentile(DDSketchHistogram::pack($counts), array_fill(0, QueryHistogram::binCount(), 0), 1000, 100000);

    expect($p95)->toBeGreaterThan(95000 * 0.98)->toBeLessThan(95000 * 1.02);
});

it('reads a sketch handed back by pdo_pgsql as a stream', function () {
    $counts = [];
    foreach (nightowlSampleDurations() as $duration) {
        DDSketchHistogram::add($counts, $duration);
    }
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, DDSketchHistogram::pack($counts));

    expect(Performance::percentile($stream, [], 1000, 100000))->toBeGreaterThan(93000)->toBeLessThan(97000);
});

it('falls back to the v1 histogram bins for rows without a sketch', function () {
    $bins = array_fill(0, QueryHistogram::binCount(), 0);
    foreach (nightowlSampleDurations() as $duration) {
        $bins[QueryHistogram::binIndex($duration)]++;
    }

    // v1 bins are sqrt(2) wide, so the estimate is coarser than the sketch's 1%.
    expect(Performance::percentile(null, $bins, 1000, 100000))->toBeGreaterThan(95000 * 0.8)->toBeLessThan(95000 * 1.2);
});

it('clamps to the observed max and returns null without data', function () {
    $counts = [];
    DDSketchHistogram::add($counts, 50000);

    expect(Performance::percentile(DDSketchHistogram::pack($counts), [], 50000, 50000))->toBe(50000.0)
        ->and(Performance::percentile(null, [], null, null))->toBeNull()
        ->and(Performance::percentile('', array_fill(0, QueryHistogram::binCount(), 0), null, null))->toBeNull();
});

it('picks the rollup tier by window', function () {
    expect(Performance::table('nightowl_request_rollups', 24))->toBe('nightowl_request_rollups')
        ->and(Performance::table('nightowl_request_rollups', 24 * 7))->toBe('nightowl_request_hourly_rollups')
        ->and(Performance::table('nightowl_request_rollups', 24 * 365))->toBe('nightowl_request_daily_rollups');
});
