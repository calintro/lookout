<?php

use Lookout\Services\Performance;
use NightOwl\Support\DDSketchHistogram;

it('merges a comma list of hex sketches like the agent merge does', function () {
    $a = $b = [];
    foreach ([120, 4_500, 4_600, 90_000] as $us) {
        DDSketchHistogram::add($a, $us);
    }
    foreach ([130, 4_500, 2_000_000] as $us) {
        DDSketchHistogram::add($b, $us);
    }
    $pa = DDSketchHistogram::pack($a);
    $pb = DDSketchHistogram::pack($b);

    expect(Performance::mergeSketches(bin2hex($pa).','.bin2hex($pb)))->toBe(DDSketchHistogram::merge($pa, $pb))
        ->and(Performance::mergeSketches(null))->toBe('')
        ->and(Performance::mergeSketches(bin2hex($pa)))->toBe($pa)
        ->and(Performance::mergeSketches(bin2hex($pa).','.bin2hex($pb).','.bin2hex($pa)))
        ->toBe(DDSketchHistogram::merge(DDSketchHistogram::merge($pa, $pb), $pa));
});
