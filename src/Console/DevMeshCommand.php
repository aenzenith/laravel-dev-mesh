<?php

namespace Aenzenith\DevMesh\Console;

use Aenzenith\DevMesh\MeshEvent;
use Aenzenith\DevMesh\MeshKeyboard;
use Aenzenith\DevMesh\MeshOutputParser;
use Aenzenith\DevMesh\MeshProcess;
use Aenzenith\DevMesh\MeshProjectStats;
use Aenzenith\DevMesh\MeshScreen;
use Aenzenith\DevMesh\MeshWatcher;
use Aenzenith\DevMesh\ProcessTreeMemory;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Starts the configured long-running services (Horizon and `schedule:work`
 * by default) for every configured project and shows them as a live
 * dashboard: one row per project with a cell per service (uptime, PID,
 * memory, restart history) and the project's totals (finished / failed work,
 * throughput, latest job, latest error), plus a feed of the latest events.
 *
 * A crashed service is restarted with a growing back-off; a code change
 * restarts the services of its project. The arrow keys select a cell, `r`
 * restarts its service and `s` stops or starts it. Without a TTY (piped, CI)
 * the dashboard is skipped and child output is printed as
 * `[project|service] line`. Blocks until `q` or Ctrl+C, which stops every
 * child.
 *
 * @phpstan-import-type MeshCell from MeshScreen
 * @phpstan-import-type MeshSummary from MeshScreen
 */
class DevMeshCommand extends Command implements SignalableCommandInterface
{
    protected $description = 'Run long-running services of your Laravel projects in a live dashboard (q or Ctrl+C stops all)';

    private const MAX_EVENTS = 200;

    private const MEMORY_SAMPLE_SECONDS = 2;

    private const WATCH_SECONDS = 1;

    /** Grace period for SIGTERM before a child is killed. */
    private const STOP_SECONDS = 15;

    private bool $stopping = false;

    /** @var array<string, array<string, MeshProcess>> project => service => process */
    private array $grid = [];

    /** @var array{0: int, 1: int} project and service index of the selected cell */
    private array $selected = [0, 0];

    /** @var list<MeshEvent> newest first */
    private array $events = [];

    /** @var array<string, MeshProjectStats> */
    private array $stats = [];

    /** @var array<int, int> pid => resident memory in KB */
    private array $memory = [];

    private float $memorySampledAt = 0.0;

    public function __construct()
    {
        $name = config('dev-mesh.command', 'dev-mesh');
        $this->signature = is_string($name) && $name !== '' ? $name : 'dev-mesh';

        parent::__construct();
    }

    /**
     * Configured projects as `label => path`; a plain list uses each
     * directory name as the label, an empty one the current application.
     *
     * @param  array<array-key, mixed>  $configured
     * @return array<string, string>
     */
    public static function resolveProjects(array $configured, string $basePath): array
    {
        if ($configured === []) {
            return [basename($basePath) => $basePath];
        }

        $projects = [];

        foreach ($configured as $label => $path) {
            if (is_string($path) && $path !== '') {
                $projects[is_string($label) ? $label : basename($path)] = $path;
            }
        }

        return $projects;
    }

    /**
     * @param  array<array-key, mixed>  $configured
     * @return array<string, list<string>>
     */
    public static function resolveServices(array $configured): array
    {
        $services = [];

        foreach ($configured as $name => $command) {
            if (is_string($name) && is_array($command) && $command !== []) {
                $services[$name] = array_values(array_map(fn (mixed $part): string => is_scalar($part) ? (string) $part : '', $command));
            }
        }

        return $services;
    }

    public function handle(): int
    {
        $projects = self::resolveProjects($this->configArray('dev-mesh.projects'), base_path());
        $services = self::resolveServices($this->configArray('dev-mesh.services'));
        $labels = $this->labels();
        $parser = new MeshOutputParser($labels['background'] ?? '(background)');
        $screen = new MeshScreen($labels, $this->title());
        $processes = $this->processes($projects, $services);
        $environment = MeshProcess::cleanEnvironment(
            getenv(),
            $this->stringList('dev-mesh.environment.keep'),
            $this->stringList('dev-mesh.environment.keep_prefixes'),
        );
        $interactive = stream_isatty(STDOUT) && stream_isatty(STDIN);
        $watchers = $this->watchers($projects);
        $meshStartedAt = microtime(true);

        foreach (array_keys($projects) as $project) {
            $this->stats[$project] = new MeshProjectStats;
        }

        foreach ($processes as $process) {
            if ($process->exists()) {
                $process->start($environment, $meshStartedAt);
            }
        }

        $keyboard = new MeshKeyboard(STDIN);
        $savedTerminal = $interactive ? $this->enterDashboard() : null;

        if (! $interactive) {
            $this->line($this->trans('started', ['projects' => count($projects), 'services' => count($services)]));
        }

        try {
            $lastFrameAt = 0.0;
            $watchedAt = $meshStartedAt;

            while (! $this->stopping) {
                $now = microtime(true);

                foreach ($processes as $process) {
                    $this->supervise($process, $parser, $environment, $now, $interactive);
                }

                if ($now - $watchedAt >= self::WATCH_SECONDS) {
                    $this->reloadChanged($watchers, $environment, $now);
                    $watchedAt = $now;
                }

                if ($interactive) {
                    $keys = $keyboard->pressed();

                    if ($keys !== []) {
                        $this->handleKeys($keys, $environment, $now);
                        $lastFrameAt = 0.0;
                    }

                    if ($now - $lastFrameAt >= 0.5) {
                        $this->drawFrame($screen, array_keys($services), $processes, $now, $meshStartedAt);
                        $lastFrameAt = $now;
                    }
                }

                usleep(100_000);
            }
        } finally {
            // A failing write must never skip stopping the children: they
            // would keep running (and consuming queues) without an owner.
            try {
                if ($interactive) {
                    $this->writeRaw("\e[H\e[2J ".$this->trans('stopping'));
                }
            } finally {
                $this->stopAll($processes);

                if ($savedTerminal !== null) {
                    $this->leaveDashboard($savedTerminal);
                }
            }

            $this->line($this->trans('stopped'));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return [SIGINT, SIGTERM];
    }

    /**
     * Do not exit from inside the handler: flag the loop so `finally` stops
     * the children and restores the terminal.
     */
    public function handleSignal(int $signal, false|int $previousExitCode = 0): false|int
    {
        $this->stopping = true;

        return false;
    }

    /**
     * @param  array<string, string>  $projects
     * @param  array<string, list<string>>  $services
     * @return list<MeshProcess>
     */
    private function processes(array $projects, array $services): array
    {
        $processes = [];

        foreach ($projects as $project => $path) {
            foreach ($services as $service => $command) {
                $processes[] = $this->grid[$project][$service] = new MeshProcess($project, $service, $path, $command);
            }
        }

        return $processes;
    }

    /**
     * @param  array<string, string>  $projects
     * @return array<string, MeshWatcher>
     */
    private function watchers(array $projects): array
    {
        $paths = $this->stringList('dev-mesh.watch.paths');
        $watchers = [];

        foreach ($projects as $project => $path) {
            if ($paths !== [] && is_dir($path)) {
                $watchers[$project] = new MeshWatcher($path, $paths);
            }
        }

        return $watchers;
    }

    /**
     * Restarts the services of every project whose code changed, except the
     * ones listed in `dev-mesh.watch.except` and the ones stopped by hand.
     *
     * @param  array<string, MeshWatcher>  $watchers
     * @param  list<string>  $environment
     */
    private function reloadChanged(array $watchers, array $environment, float $now): void
    {
        $except = $this->stringList('dev-mesh.watch.except');

        foreach ($watchers as $project => $watcher) {
            $file = $watcher->changed();

            if ($file === null) {
                continue;
            }

            foreach ($this->grid[$project] ?? [] as $service => $process) {
                if (! $process->held && ! in_array($service, $except, true) && $this->restartService($process, $environment, $now)) {
                    $this->record(new MeshEvent(date('H:i:s'), $process->project, $service, $this->trans('reloaded', ['file' => $file]), MeshEvent::WARN), $now);
                }
            }
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $environment
     */
    private function handleKeys(array $keys, array $environment, float $now): void
    {
        $projects = array_map(strval(...), array_keys($this->grid));
        $services = array_map(strval(...), array_keys(array_values($this->grid)[0] ?? []));
        [$row, $column] = $this->selected;

        foreach ($keys as $key) {
            $key = strtolower($key);

            [$rowStep, $columnStep] = match ($key) {
                'up', 'k' => [-1, 0],
                'down', 'j' => [1, 0],
                'left', 'h' => [0, -1],
                'right', 'l' => [0, 1],
                default => [0, 0],
            };

            $row = max(0, min(count($projects) - 1, $row + $rowStep));
            $column = max(0, min(count($services) - 1, $column + $columnStep));
            $process = $this->grid[$projects[$row] ?? ''][$services[$column] ?? ''] ?? null;

            if ($key === 'q') {
                $this->stopping = true;
            } elseif ($key === 'r' && $process !== null) {
                $this->restartService($process, $environment, $now);
            } elseif ($key === 's' && $process !== null) {
                $this->toggleService($process, $environment, $now);
            }
        }

        $this->selected = [$row, $column];
    }

    /**
     * Restarts a running service gracefully and starts a stopped or crashed
     * one at once; false when there is nothing to start.
     *
     * @param  list<string>  $environment
     */
    private function restartService(MeshProcess $process, array $environment, float $now): bool
    {
        if ($this->stopping || ! $process->exists()) {
            return false;
        }

        if ($process->isRunning()) {
            $process->requestStop($now, restart: true);
        } else {
            $process->start($environment, $now);
        }

        return true;
    }

    /**
     * Holds a service (no restarts until started again) or starts a held one.
     *
     * @param  list<string>  $environment
     */
    private function toggleService(MeshProcess $process, array $environment, float $now): void
    {
        if ($this->stopping || ! $process->exists()) {
            return;
        }

        if (! $process->held) {
            $process->requestStop($now, restart: false);
        } elseif ($process->isRunning()) {
            // Still shutting down: start it again as soon as it exits.
            $process->requestStop($now, restart: true);
        } else {
            $process->start($environment, $now);
        }
    }

    /**
     * @param  list<string>  $environment
     */
    private function supervise(MeshProcess $process, MeshOutputParser $parser, array $environment, float $now, bool $interactive): void
    {
        if (! $process->exists()) {
            return;
        }

        $exited = $process->hasJustExited();

        foreach ($process->pullLines(flush: $exited) as $line) {
            if (! $interactive) {
                $this->line("[{$process->project}|{$process->service}] {$line}");
            }

            $event = $parser->parse($process->project, $process->service, $line, date('H:i:s'));

            if ($event !== null) {
                $this->record($event, $now);
            }
        }

        if ($exited && $process->stopRequestedAt !== null) {
            if ($process->settleRequestedStop() && ! $this->stopping) {
                $process->start($environment, $now);
            }

            return;
        }

        if ($process->stopRequestedAt !== null && $now - $process->stopRequestedAt >= self::STOP_SECONDS) {
            $process->kill();

            return;
        }

        if ($exited) {
            $delay = min(2 ** $process->restarts, $this->maxRestartDelay());
            $process->markExited($now + $delay);

            if (! $this->stopping) {
                $message = $this->trans('crashed', ['code' => (string) $process->lastExitCode, 'seconds' => $delay]);
                $this->record(new MeshEvent(date('H:i:s'), $process->project, $process->service, $message, MeshEvent::ERROR), $now);
            }

            return;
        }

        if (! $this->stopping && $process->restartAt !== null && $now >= $process->restartAt) {
            $process->restart($environment, $now);
            $this->record(new MeshEvent(date('H:i:s'), $process->project, $process->service, $this->trans('restarted'), MeshEvent::WARN), $now);
        }
    }

    private function record(MeshEvent $event, float $now): void
    {
        array_unshift($this->events, $event);
        $this->events = array_slice($this->events, 0, self::MAX_EVENTS);

        ($this->stats[$event->project] ??= new MeshProjectStats)->record($event, $now);
    }

    /**
     * @param  list<string>  $services
     * @param  list<MeshProcess>  $processes
     */
    private function drawFrame(MeshScreen $screen, array $services, array $processes, float $now, float $meshStartedAt): void
    {
        if ($now - $this->memorySampledAt >= self::MEMORY_SAMPLE_SECONDS) {
            $this->memory = ProcessTreeMemory::sample(array_values(array_filter(array_map(fn (MeshProcess $process): ?int => $process->pid(), $processes))));
            $this->memorySampledAt = $now;
        }

        /** @var array<string, array<string, MeshCell>> $matrix */
        $matrix = [];

        foreach ($processes as $process) {
            $pid = $process->pid();

            $matrix[$process->project][$process->service] = [
                'state' => match (true) {
                    ! $process->exists() => 'missing',
                    $process->stopRequestedAt !== null => $process->held ? 'stopping' : 'restarting',
                    $pid !== null => 'running',
                    $process->held => 'stopped',
                    default => 'down',
                },
                'uptime' => $pid !== null && $process->startedAt !== null ? (int) ($now - $process->startedAt) : null,
                'restarts' => $process->restarts,
                'retryIn' => $process->restartAt !== null ? max(0, (int) ceil($process->restartAt - $now)) : null,
                'pid' => $pid,
                'memoryKb' => $pid !== null ? ($this->memory[$pid] ?? null) : null,
                'lastExitCode' => $process->lastExitCode,
                'lastRestartAt' => $process->lastRestartAt !== null ? date('H:i', (int) $process->lastRestartAt) : null,
            ];
        }

        /** @var array<string, MeshSummary> $summaries */
        $summaries = array_map(fn (MeshProjectStats $stats): array => [
            'done' => $stats->done,
            'failed' => $stats->failed,
            'perMinute' => $stats->perMinute($now, $meshStartedAt),
            'lastJob' => $stats->lastJob,
            'lastError' => $stats->lastError,
        ], $this->stats);

        $terminal = new Terminal;
        $selected = [(string) (array_keys($this->grid)[$this->selected[0]] ?? ''), $services[$this->selected[1]] ?? ''];
        $frame = $screen->render($services, $matrix, $summaries, $this->events, $terminal->getWidth(), $terminal->getHeight(), date('H:i:s'), (int) ($now - $meshStartedAt), $selected);

        $this->writeRaw("\e[H".str_replace("\n", "\e[K\n", $frame)."\e[K\e[J");
    }

    /**
     * @param  list<MeshProcess>  $processes
     */
    private function stopAll(array $processes): void
    {
        foreach ($processes as $process) {
            $process->terminate();
        }

        $deadline = microtime(true) + self::STOP_SECONDS;

        while (microtime(true) < $deadline && array_filter($processes, fn (MeshProcess $process): bool => $process->isRunning()) !== []) {
            usleep(100_000);
        }

        foreach ($processes as $process) {
            $process->kill();
        }
    }

    /**
     * Alternate screen + hidden cursor + unbuffered, silent key input.
     * Returns the previous `stty` state for {@see leaveDashboard()}.
     */
    private function enterDashboard(): string
    {
        $saved = trim((string) shell_exec('stty -g'));
        shell_exec('stty -icanon -echo');

        $this->writeRaw("\e[?1049h\e[?25l\e[2J");

        return $saved;
    }

    private function leaveDashboard(string $savedTerminal): void
    {
        $this->writeRaw("\e[?25h\e[?1049l");

        if ($savedTerminal !== '') {
            shell_exec('stty '.escapeshellarg($savedTerminal));
        }
    }

    private function writeRaw(string $text): void
    {
        $this->output->write($text, false, OutputInterface::OUTPUT_RAW);
    }

    /**
     * @return array<string, string>
     */
    private function labels(): array
    {
        $labels = trans('dev-mesh::dashboard');

        return is_array($labels) ? array_filter($labels, is_string(...)) : [];
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private function trans(string $key, array $replace = []): string
    {
        $text = trans("dev-mesh::dashboard.{$key}", $replace);

        return is_string($text) ? $text : $key;
    }

    private function title(): string
    {
        $title = config('dev-mesh.title') ?? config('app.name');

        return is_string($title) && $title !== '' ? $title : 'Dev mesh';
    }

    private function maxRestartDelay(): int
    {
        $delay = config('dev-mesh.max_restart_delay', 30);

        return is_numeric($delay) ? max(1, (int) $delay) : 30;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function configArray(string $key): array
    {
        $value = config($key);

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        return array_values(array_filter($this->configArray($key), is_string(...)));
    }
}
