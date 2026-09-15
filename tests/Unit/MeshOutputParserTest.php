<?php

use Aenzenith\DevMesh\MeshEvent;
use Aenzenith\DevMesh\MeshOutputParser;

function parseMeshLine(string $line, string $service = 'horizon', string $backgroundSuffix = '(background)'): ?MeshEvent
{
    return (new MeshOutputParser($backgroundSuffix))->parse('shop', $service, $line, '09:00:00');
}

it('parses a finished queue job with its runtime', function () {
    $event = parseMeshLine('  2026-09-15 17:42:08 App\Jobs\SyncUsersJob ................................ 120.34ms DONE');

    expect($event)->not->toBeNull()
        ->and($event->time)->toBe('17:42:08')
        ->and($event->label)->toBe('App\Jobs\SyncUsersJob')
        ->and($event->duration)->toBe('120.34ms')
        ->and($event->status)->toBe(MeshEvent::DONE)
        ->and($event->project)->toBe('shop');
});

it('drops the running half of a queue job', function () {
    expect(parseMeshLine('  2026-09-15 17:42:08 App\Jobs\SyncUsersJob ....................... RUNNING'))->toBeNull();
});

it('strips ansi colours from a failed job line', function () {
    $event = parseMeshLine("  \e[90m2026-09-15 17:42:05\e[39m App\\Jobs\\DeliverWebhookJob \e[90m.......\e[39m \e[90m1.02s\e[39m \e[31;1mFAIL\e[39;22m");

    expect($event?->status)->toBe(MeshEvent::FAIL)
        ->and($event?->label)->toBe('App\Jobs\DeliverWebhookJob')
        ->and($event?->duration)->toBe('1.02s');
});

it('shortens a scheduled artisan command to its name', function () {
    $event = parseMeshLine("  2026-09-15 17:42:00 Running ['artisan' reports:rollup] ................... 1s DONE", 'schedule');
    $background = parseMeshLine("  2026-09-15 17:43:00 Running ['artisan' horizon:snapshot] in background ..... 3.5ms DONE", 'schedule');
    $translated = parseMeshLine("  2026-09-15 17:43:00 Running ['artisan' horizon:snapshot] in background ..... 3.5ms DONE", 'schedule', '(arka plan)');

    expect($event?->label)->toBe('reports:rollup')
        ->and($event?->time)->toBe('17:42:00')
        ->and($background?->label)->toBe('horizon:snapshot (background)')
        ->and($translated?->label)->toBe('horizon:snapshot (arka plan)');
});

it('ignores idle scheduler ticks and stack trace lines', function (string $line) {
    expect(parseMeshLine($line, 'schedule'))->toBeNull();
})->with([
    '   INFO  No scheduled commands are ready to run.',
    '   INFO  Running scheduled tasks every minute.',
    '#3 /srv/shop/app/Jobs/SyncUsersJob.php(42): handle()',
    '      +17 vendor frames',
    '',
]);

it('keeps notable raw lines with a level', function () {
    $info = parseMeshLine('   INFO  Horizon started successfully.');
    $error = parseMeshLine('   ERROR  SQLSTATE[HY000] [2002] Connection refused');
    $exception = parseMeshLine('RuntimeException: Redis went away');

    expect($info?->status)->toBe(MeshEvent::INFO)
        ->and($info?->label)->toBe('Horizon started successfully.')
        ->and($info?->time)->toBe('09:00:00')
        ->and($error?->status)->toBe(MeshEvent::ERROR)
        ->and($exception?->status)->toBe(MeshEvent::ERROR);
});
