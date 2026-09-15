<?php

use Aenzenith\DevMesh\MeshEvent;
use Aenzenith\DevMesh\MeshScreen;
use Symfony\Component\Console\Helper\Helper;

function plainMeshFrame(string $frame): string
{
    return (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $frame);
}

/**
 * @return array<string, string>
 */
function meshLabels(string $locale): array
{
    return require dirname(__DIR__, 2)."/lang/{$locale}/dashboard.php";
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function meshCell(array $overrides = []): array
{
    return array_replace([
        'state' => 'running',
        'uptime' => 4353,
        'restarts' => 0,
        'retryIn' => null,
        'pid' => 84211,
        'memoryKb' => 145408,
        'lastExitCode' => null,
        'lastRestartAt' => null,
    ], $overrides);
}

/**
 * @return array<string, array<string, array<string, mixed>>>
 */
function meshMatrix(): array
{
    $missing = meshCell(['state' => 'missing', 'uptime' => null, 'pid' => null, 'memoryKb' => null]);

    return [
        'shop' => [
            'horizon' => meshCell(),
            'schedule' => meshCell(['pid' => 84212, 'memoryKb' => 38912, 'restarts' => 2, 'lastRestartAt' => '16:05', 'lastExitCode' => 137]),
        ],
        'payments-api' => [
            'horizon' => meshCell(['state' => 'down', 'uptime' => null, 'pid' => null, 'memoryKb' => null, 'restarts' => 1, 'retryIn' => 4, 'lastExitCode' => 255, 'lastRestartAt' => '17:41']),
            'schedule' => meshCell(['uptime' => 61, 'pid' => 84390, 'memoryKb' => 36864]),
        ],
        'legacy-admin' => ['horizon' => $missing, 'schedule' => $missing],
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function meshSummaries(): array
{
    return [
        'shop' => [
            'done' => 128,
            'failed' => 2,
            'perMinute' => 1.5,
            'lastJob' => new MeshEvent('17:42:08', 'shop', 'horizon', 'App\Jobs\SyncUsersJob', MeshEvent::DONE, '120ms'),
            'lastError' => new MeshEvent('17:30:00', 'shop', 'horizon', 'App\Jobs\DeliverWebhookJob', MeshEvent::FAIL, '1s'),
        ],
        'payments-api' => ['done' => 0, 'failed' => 0, 'perMinute' => 0.0, 'lastJob' => null, 'lastError' => null],
    ];
}

/**
 * @param  list<MeshEvent>  $events
 * @param  array<string, array<string, mixed>>|null  $summaries
 */
function renderMesh(int $width, int $height = 40, array $events = [], ?array $summaries = null, string $locale = 'tr'): string
{
    return (new MeshScreen(meshLabels($locale), 'Shop mesh'))
        ->render(['horizon', 'schedule'], meshMatrix(), $summaries ?? meshSummaries(), $events, $width, $height, '17:42:10', 4353);
}

function meshRow(string $frame, string $needle): string
{
    foreach (explode("\n", $frame) as $line) {
        if (str_contains($line, $needle)) {
            return $line;
        }
    }

    return '';
}

it('draws one row per project with service cells and project totals', function () {
    $frame = plainMeshFrame(renderMesh(220));

    expect(meshRow($frame, 'Proje'))->toMatch('/Proje\s+│\s+horizon\s+│\s+schedule\s+│\s+✓ İş\s+│\s+✕ Hata\s+│\s+İş\/dk\s+│\s+Son işlem\s+│\s+Son hata/u')
        ->and(meshRow($frame, 'shop'))->toMatch('/│\s+● 01:12:33 84211 142 MB\s+│\s+● 01:12:33 84212 38 MB ↻2 · 16:05 · çıkış 137\s+│\s+128\s+│\s+2\s+│\s+1\.5\s+│\s+17:42:08 SyncUsersJob ✓\s+│\s+17:30:00 DeliverWebhookJob\s+│/u')
        ->and(meshRow($frame, 'payments-api'))->toMatch('/│\s+✕ çıkış 255 · 4 sn ↻1 · 17:41\s+│\s+● 00:01:01 84390 36 MB\s+│\s+0\s+│\s+0\s+│\s+0\.0\s+│\s+—\s+│\s+—\s+│/u')
        ->and(meshRow($frame, 'legacy-admin'))->toMatch('/— dizin yok\s+│\s+— dizin yok\s+│\s+—\s+│\s+—\s+│\s+—\s+│/u')
        ->and($frame)->toContain('Shop mesh')
        ->and($frame)->toContain('Henüz işlem yok');
});

it('renders the labels of the given language', function () {
    $frame = plainMeshFrame(renderMesh(220, locale: 'en'));

    expect(meshRow($frame, 'Project'))->toMatch('/Project\s+│\s+horizon\s+│\s+schedule\s+│\s+✓ Done\s+│\s+✕ Failed\s+│\s+Jobs\/min\s+│\s+Last job\s+│\s+Last error/u')
        ->and(meshRow($frame, 'payments-api'))->toContain('✕ exit 255 · 4 s ↻1 · 17:41')
        ->and(meshRow($frame, 'legacy-admin'))->toContain('— no directory')
        ->and($frame)->toContain('q / Ctrl+C to quit')
        ->and($frame)->toContain('Nothing yet');
});

it('drops the latest error, then the latest job column when the terminal is narrow', function () {
    $wide = plainMeshFrame(renderMesh(220));
    $narrow = plainMeshFrame(renderMesh(60));

    expect(meshRow($wide, 'Proje'))->toContain('Son işlem')->toContain('Son hata')
        ->and(meshRow($narrow, 'Proje'))->not->toContain('Son işlem')->not->toContain('Son hata');

    foreach (explode("\n", $wide) as $line) {
        expect(Helper::width($line))->toBeLessThanOrEqual(220);
    }

    $fixedWidth = Helper::width(meshRow($narrow, 'Proje'));

    expect(meshRow(plainMeshFrame(renderMesh($fixedWidth + 20)), 'Proje'))->toContain('Son işlem')->not->toContain('Son hata')
        ->and(meshRow(plainMeshFrame(renderMesh($fixedWidth + 40)), 'Proje'))->toContain('Son işlem')->toContain('Son hata');
});

it('lists the newest events first and fits the terminal height', function () {
    $events = [];

    foreach (range(1, 50) as $i) {
        array_unshift($events, new MeshEvent(sprintf('17:%02d:00', $i), 'payments-api', 'horizon', "App\\Jobs\\Job{$i}", MeshEvent::DONE, '12ms'));
    }

    $frame = plainMeshFrame(renderMesh(220, 24, $events));

    expect(count(explode("\n", $frame)))->toBeLessThanOrEqual(23)
        ->and(strpos($frame, 'Job50'))->toBeLessThan((int) strpos($frame, 'Job49'))
        ->and($frame)->not->toContain('App\Jobs\Job1 ');
});

it('prints labels literally in the feed and in table cells', function () {
    $events = [new MeshEvent('17:42:05', 'payments-api', 'horizon', 'App\Jobs\<Weird>Job', MeshEvent::FAIL, '1.02s')];
    $summaries = meshSummaries();
    $summaries['payments-api']['lastError'] = new MeshEvent('17:42:06', 'payments-api', 'horizon', 'Bad <tag> in C:\path\\', MeshEvent::ERROR);

    $raw = renderMesh(260, 40, $events, $summaries);
    $frame = plainMeshFrame($raw);

    expect($frame)->toMatch('/17:42:05\s+payments-api\s+horizon\s+App\\\\Jobs\\\\<Weird>Job \.+ 1\.02s FAIL/u')
        ->and(meshRow($frame, 'payments-api'))->toContain('17:42:06 Bad <tag> in C:\path\\')
        ->and($raw)->toContain("\e[");
});
