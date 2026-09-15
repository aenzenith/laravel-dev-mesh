<?php

use Aenzenith\DevMesh\MeshKeyboard;

/**
 * @return array{0: resource, 1: resource}
 */
function keyboardPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    expect($pair)->toBeArray();

    return $pair;
}

it('returns at once when no key is waiting and keeps the stream blocking', function () {
    [$input, $terminal] = keyboardPair();
    $keyboard = new MeshKeyboard($input);

    $startedAt = microtime(true);

    expect($keyboard->quitPressed())->toBeFalse()
        ->and(microtime(true) - $startedAt)->toBeLessThan(0.05)
        ->and(stream_get_meta_data($input)['blocked'])->toBeTrue();

    fclose($terminal);
});

it('reacts to q in either case and ignores other keys', function () {
    [$input, $terminal] = keyboardPair();
    $keyboard = new MeshKeyboard($input);

    fwrite($terminal, 'x');
    expect($keyboard->quitPressed())->toBeFalse();

    fwrite($terminal, 'Q');
    expect($keyboard->quitPressed())->toBeTrue();
});

it('never switches the terminal streams to non-blocking mode', function () {
    expect(file_get_contents(dirname(__DIR__, 2).'/src/Console/DevMeshCommand.php'))->not->toContain('stream_set_blocking');
});
