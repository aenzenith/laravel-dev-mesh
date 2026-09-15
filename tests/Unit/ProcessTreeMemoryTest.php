<?php

use Aenzenith\DevMesh\ProcessTreeMemory;

it('sums resident memory of each root and all of its descendants', function () {
    $ps = implode("\n", [
        '    1     0   5872',
        '  100     1  20480',
        '  101   100  51200',
        '  102   101  10240',
        '  200     1   8192',
        '  300     1   4096',
        'garbage line',
        '',
    ]);

    expect(ProcessTreeMemory::fromPsOutput($ps, [100, 200, 999]))->toBe([100 => 81920, 200 => 8192]);
});

it('returns nothing when there is no process to measure', function () {
    expect(ProcessTreeMemory::sample([]))->toBe([]);
});
