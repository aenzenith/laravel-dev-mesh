<?php

namespace Aenzenith\DevMesh;

/**
 * Non-blocking key polling for the dashboard.
 *
 * The input stream is never switched to non-blocking mode: on a terminal
 * STDIN and STDOUT share one open file description, so O_NONBLOCK on STDIN
 * also applies to STDOUT and frames the terminal cannot take at once are
 * silently cut (2026-09-15: the dashboard froze on a half-drawn header).
 * `stream_select` with a zero timeout asks whether a key is waiting instead.
 */
final class MeshKeyboard
{
    /**
     * @param  resource  $input
     */
    public function __construct(private $input) {}

    public function quitPressed(): bool
    {
        $read = [$this->input];
        $write = null;
        $except = null;

        if ((int) @stream_select($read, $write, $except, 0) < 1) {
            return false;
        }

        $bytes = fread($this->input, 64);

        return is_string($bytes) && str_contains(strtolower($bytes), 'q');
    }
}
