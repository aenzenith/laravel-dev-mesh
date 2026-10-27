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
    /** Final byte of an arrow key's escape sequence (`ESC [ A`, `ESC O A`). */
    private const ARROWS = ['A' => 'up', 'B' => 'down', 'C' => 'right', 'D' => 'left'];

    /**
     * @param  resource  $input
     */
    public function __construct(private $input) {}

    /**
     * Keys pressed since the last call, oldest first: the arrow keys as
     * `up`, `down`, `left` and `right`, any other key as the character it
     * types. Other escape sequences (function keys, Esc) are dropped.
     *
     * @return list<string>
     */
    public function pressed(): array
    {
        $read = [$this->input];
        $write = null;
        $except = null;

        if ((int) @stream_select($read, $write, $except, 0) < 1) {
            return [];
        }

        $bytes = fread($this->input, 64);

        if (! is_string($bytes) || preg_match_all('/\e[\[O][0-9;]*[A-Za-z~]|\e|./s', $bytes, $matches) === false) {
            return [];
        }

        $keys = [];

        foreach ($matches[0] as $key) {
            if (! str_starts_with($key, "\e")) {
                $keys[] = $key;
            } elseif (isset(self::ARROWS[substr($key, -1)])) {
                $keys[] = self::ARROWS[substr($key, -1)];
            }
        }

        return $keys;
    }
}
