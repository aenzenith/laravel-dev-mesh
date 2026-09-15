<?php

use Aenzenith\DevMesh\MeshEvent;
use Aenzenith\DevMesh\MeshProjectStats;

function statsEvent(string $status, string $label = 'App\Jobs\SyncSchoolJob'): MeshEvent
{
    return new MeshEvent('17:00:00', 'cognicise-core', 'horizon', $label, $status, '10ms');
}

it('counts finished and failed work and remembers the latest of each', function () {
    $stats = new MeshProjectStats;

    $stats->record(statsEvent(MeshEvent::DONE, 'A'), 1000.0);
    $stats->record(statsEvent(MeshEvent::FAIL, 'B'), 1001.0);
    $stats->record(statsEvent(MeshEvent::ERROR, 'Connection refused'), 1002.0);
    $stats->record(statsEvent(MeshEvent::DONE, 'C'), 1003.0);
    $stats->record(statsEvent(MeshEvent::INFO, 'Horizon started successfully.'), 1004.0);

    expect($stats->done)->toBe(2)
        ->and($stats->failed)->toBe(2)
        ->and($stats->lastJob?->label)->toBe('C')
        ->and($stats->lastError?->label)->toBe('Connection refused');
});

it('measures throughput over the last five minutes', function () {
    $stats = new MeshProjectStats;

    foreach (range(0, 9) as $second) {
        $stats->record(statsEvent(MeshEvent::DONE), 1000.0 + $second);
    }

    expect($stats->perMinute(1010.0, 990.0))->toBe(10.0);

    $stats->record(statsEvent(MeshEvent::DONE), 1400.0);

    expect($stats->perMinute(1400.0, 0.0))->toBe(0.2);
});
