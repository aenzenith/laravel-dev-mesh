<?php

use Aenzenith\DevMesh\Console\DevMeshCommand;
use Illuminate\Support\Facades\Artisan;

it('registers the dashboard command under the configured name', function () {
    expect(Artisan::all())->toHaveKey('dev')
        ->and(Artisan::all()['dev'])->toBeInstanceOf(DevMeshCommand::class);

    config(['dev-mesh.command' => 'mesh']);

    expect((new DevMeshCommand)->getName())->toBe('mesh');
});

it('merges the default configuration', function () {
    expect(config('dev-mesh.services'))->toBe([
        'horizon' => ['php', 'artisan', 'horizon'],
        'schedule' => ['php', 'artisan', 'schedule:work'],
    ])
        ->and(config('dev-mesh.environment.keep_prefixes'))->toBe(['HERD_', 'NVM_'])
        ->and(config('dev-mesh.max_restart_delay'))->toBe(30);
});

it('runs the current application when no project is configured', function () {
    expect(DevMeshCommand::resolveProjects([], '/srv/apps/shop'))->toBe(['shop' => '/srv/apps/shop']);
});

it('accepts labelled projects and plain paths', function () {
    expect(DevMeshCommand::resolveProjects(['api' => '/srv/api', '/srv/admin', 'broken' => 42], '/srv/shop'))
        ->toBe(['api' => '/srv/api', 'admin' => '/srv/admin']);
});

it('keeps only named services with a command', function () {
    expect(DevMeshCommand::resolveServices([
        'horizon' => ['php', 'artisan', 'horizon'],
        'empty' => [],
        0 => ['php', 'artisan', 'queue:work'],
        'reverb' => ['php', 'artisan', 'reverb:start', '--port', 8080],
    ]))->toBe([
        'horizon' => ['php', 'artisan', 'horizon'],
        'reverb' => ['php', 'artisan', 'reverb:start', '--port', '8080'],
    ]);
});

it('ships the dashboard in english and turkish', function (string $locale, string $feed, string $crashed) {
    app()->setLocale($locale);

    expect(__('dev-mesh::dashboard.feed'))->toBe($feed)
        ->and(__('dev-mesh::dashboard.crashed', ['code' => 255, 'seconds' => 4]))->toBe($crashed);
})->with([
    ['en', 'Recent activity', 'stopped (exit 255), restarting in 4 s'],
    ['tr', 'Son işlemler', 'durdu (çıkış 255), 4 sn sonra yeniden başlatılacak'],
]);
