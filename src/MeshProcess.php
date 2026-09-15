<?php

namespace Aenzenith\DevMesh;

use Symfony\Component\Process\Process;

/**
 * One long-running child of the dashboard (a project × service cell): owns
 * the Symfony process, its partial-line buffer and restart bookkeeping.
 */
final class MeshProcess
{
    private ?Process $process = null;

    private string $buffer = '';

    public ?float $startedAt = null;

    public ?float $restartAt = null;

    public ?float $lastRestartAt = null;

    public ?int $lastExitCode = null;

    public int $restarts = 0;

    /**
     * @param  list<string>  $command
     */
    public function __construct(
        public readonly string $project,
        public readonly string $service,
        public readonly string $directory,
        private readonly array $command,
    ) {}

    /**
     * `KEY=value` pairs for `env -i`. Children start with a clean environment:
     * the variables Dotenv loaded for the dashboard's own application are real
     * process variables by now and would override every sibling project's
     * `.env`. TERM and LANG always get a sane default.
     *
     * @param  array<string, string>  $environment
     * @param  list<string>  $keep  variable names passed through
     * @param  list<string>  $keepPrefixes  variable name prefixes passed through
     * @return list<string>
     */
    public static function cleanEnvironment(array $environment, array $keep, array $keepPrefixes): array
    {
        $pairs = [
            'TERM='.($environment['TERM'] ?? 'xterm-256color'),
            'LANG='.($environment['LANG'] ?? 'en_US.UTF-8'),
        ];

        foreach ($environment as $key => $value) {
            if ($key === 'TERM' || $key === 'LANG') {
                continue;
            }

            if (in_array($key, $keep, true) || self::startsWithAny($key, $keepPrefixes)) {
                $pairs[] = "{$key}={$value}";
            }
        }

        return $pairs;
    }

    public function exists(): bool
    {
        return is_dir($this->directory);
    }

    /**
     * @param  list<string>  $environment  `KEY=value` pairs handed to `env -i`
     */
    public function start(array $environment, float $now): void
    {
        $this->process = new Process(['env', '-i', ...$environment, ...$this->command], $this->directory, null, null, null);
        $this->process->start();

        $this->startedAt = $now;
        $this->restartAt = null;
    }

    /**
     * @param  list<string>  $environment
     */
    public function restart(array $environment, float $now): void
    {
        $this->restarts++;
        $this->lastRestartAt = $now;

        $this->start($environment, $now);
    }

    public function isRunning(): bool
    {
        return $this->process?->isRunning() ?? false;
    }

    public function pid(): ?int
    {
        return $this->isRunning() ? $this->process?->getPid() : null;
    }

    /**
     * Whether the process has exited and no restart is scheduled yet.
     */
    public function hasJustExited(): bool
    {
        return $this->process !== null && $this->startedAt !== null && ! $this->process->isRunning();
    }

    public function markExited(float $restartAt): void
    {
        $this->lastExitCode = $this->process?->getExitCode();
        $this->startedAt = null;
        $this->restartAt = $restartAt;
    }

    /**
     * Complete lines written since the last call; a trailing partial line
     * stays buffered until its newline arrives (or `$flush` is set).
     *
     * @return list<string>
     */
    public function pullLines(bool $flush = false): array
    {
        if ($this->process === null) {
            return [];
        }

        $this->buffer .= $this->process->getIncrementalOutput().$this->process->getIncrementalErrorOutput();
        $this->process->clearOutput();
        $this->process->clearErrorOutput();

        $lines = preg_split('/\r\n|\r|\n/', $this->buffer) ?: [];
        $this->buffer = $flush ? '' : (string) array_pop($lines);

        return array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));
    }

    public function terminate(): void
    {
        if ($this->isRunning()) {
            $this->process?->signal(SIGTERM);
        }
    }

    public function kill(): void
    {
        $this->process?->stop(0);
    }

    /**
     * @param  list<string>  $prefixes
     */
    private static function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
