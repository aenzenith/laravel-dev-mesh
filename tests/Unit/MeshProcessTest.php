<?php

use Aenzenith\DevMesh\MeshProcess;

/**
 * @return list<string>
 */
function meshEnvironment(): array
{
    return MeshProcess::cleanEnvironment(getenv(), ['HOME', 'PATH'], []);
}

function waitForMeshProcess(MeshProcess $process): void
{
    $deadline = microtime(true) + 5;

    while ($process->isRunning() && microtime(true) < $deadline) {
        usleep(20_000);
    }
}

it('passes only the kept variables to children', function () {
    $pairs = MeshProcess::cleanEnvironment([
        'HOME' => '/Users/dev',
        'PATH' => '/usr/bin',
        'TERM' => 'xterm-kitty',
        'DB_DATABASE' => 'shop',
        'REDIS_PREFIX' => 'shop_',
        'HERD_PHP_85_INI_SCAN_DIR' => '/herd/ini',
        'NVM_DIR' => '/Users/dev/.nvm',
    ], ['HOME', 'PATH', 'TERM'], ['HERD_', 'NVM_']);

    expect($pairs)
        ->toBe(['TERM=xterm-kitty', 'LANG=en_US.UTF-8', 'HOME=/Users/dev', 'PATH=/usr/bin', 'HERD_PHP_85_INI_SCAN_DIR=/herd/ini', 'NVM_DIR=/Users/dev/.nvm']);
});

it('does not start a service whose project directory is missing', function () {
    $process = new MeshProcess('ghost', 'horizon', '/nonexistent/ghost', ['php', 'artisan', 'horizon']);

    expect($process->exists())->toBeFalse()
        ->and($process->isRunning())->toBeFalse()
        ->and($process->pullLines())->toBe([]);
});

it('collects complete output lines of a child and flushes the rest on exit', function () {
    $process = new MeshProcess('shop', 'schedule', sys_get_temp_dir(), ['printf', "one\ntwo\npartial"]);
    $process->start(meshEnvironment(), microtime(true));
    waitForMeshProcess($process);

    expect($process->hasJustExited())->toBeTrue()
        ->and($process->pullLines(flush: true))->toBe(['one', 'two', 'partial']);

    $process->markExited(microtime(true) + 1);

    expect($process->lastExitCode)->toBe(0)
        ->and($process->hasJustExited())->toBeFalse();
});

it('keeps the restart history of a crashing child', function () {
    $process = new MeshProcess('shop', 'horizon', sys_get_temp_dir(), ['sh', '-c', 'exit 3']);

    $process->start(meshEnvironment(), 100.0);
    waitForMeshProcess($process);

    expect($process->hasJustExited())->toBeTrue()
        ->and($process->pid())->toBeNull();

    $process->markExited(105.0);

    expect($process->lastExitCode)->toBe(3)
        ->and($process->restartAt)->toBe(105.0);

    $process->restart(meshEnvironment(), 106.0);
    waitForMeshProcess($process);

    expect($process->restarts)->toBe(1)
        ->and($process->lastRestartAt)->toBe(106.0)
        ->and($process->restartAt)->toBeNull()
        ->and($process->startedAt)->toBe(106.0);
});

it('exposes the pid only while the child runs', function () {
    $process = new MeshProcess('shop', 'schedule', sys_get_temp_dir(), ['sleep', '5']);
    $process->start(meshEnvironment(), microtime(true));

    expect($process->pid())->toBeInt()->toBeGreaterThan(0);

    $process->kill();

    expect($process->pid())->toBeNull();
});
