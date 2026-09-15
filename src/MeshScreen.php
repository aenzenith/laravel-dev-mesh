<?php

namespace Aenzenith\DevMesh;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders one full-screen frame of the dashboard: one row per project with a
 * cell per service plus the project's totals, the newest feed events below,
 * clipped to the terminal.
 *
 * @phpstan-type MeshCell array{state: 'running'|'down'|'missing', uptime: int|null, restarts: int, retryIn: int|null, pid: int|null, memoryKb: int|null, lastExitCode: int|null, lastRestartAt: string|null}
 * @phpstan-type MeshSummary array{done: int, failed: int, perMinute: float, lastJob: MeshEvent|null, lastError: MeshEvent|null}
 */
final class MeshScreen
{
    /** Narrowest "last job" / "last error" column worth showing. */
    private const MIN_TEXT_COLUMN = 14;

    /**
     * @param  array<string, string>  $labels  translated strings, keyed as in lang/en/dashboard.php
     */
    public function __construct(
        private readonly array $labels = [],
        private readonly string $title = 'Dev mesh',
    ) {}

    /**
     * @param  list<string>  $services  column order
     * @param  array<string, array<string, MeshCell>>  $matrix  project => service => cell
     * @param  array<string, MeshSummary>  $summaries  project => totals
     * @param  list<MeshEvent>  $events  newest first
     */
    public function render(array $services, array $matrix, array $summaries, array $events, int $width, int $height, string $clock, int $meshUptime): string
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $formatter = $output->getFormatter();

        $title = ' <options=bold>'.$this->literal($this->title).'</>  <fg=gray>·</>  '.$clock
            .'  <fg=gray>·  '.$this->literal($this->label('uptime')).'</> '.$this->clock($meshUptime);
        $hint = '<fg=gray>'.$this->literal($this->label('quit_hint')).'</> ';
        $gap = $width - Helper::width(Helper::removeDecoration($formatter, $title.$hint));
        $output->writeln($title.str_repeat(' ', max(1, $gap)).$hint);
        $output->writeln('');

        $headers = array_map($this->literal(...), [
            $this->label('project'),
            ...$services,
            $this->label('done'),
            $this->label('failed'),
            $this->label('per_minute'),
        ]);
        $rows = [];

        foreach ($matrix as $project => $cells) {
            $rows[$project] = [
                $this->literal($project),
                ...array_map(fn (string $service): string => $this->cell($cells[$service] ?? null), $services),
                ...$this->counters($summaries[$project] ?? null),
            ];
        }

        [$jobWidth, $errorWidth] = $this->textColumnWidths([$headers, ...array_values($rows)], $width, $formatter);

        $table = new Table($output);
        $table->setStyle('box')->setHeaders([
            ...$headers,
            ...($jobWidth > 0 ? [$this->literal($this->label('last_job'))] : []),
            ...($errorWidth > 0 ? [$this->literal($this->label('last_error'))] : []),
        ]);

        foreach ($rows as $project => $row) {
            $summary = $summaries[$project] ?? null;

            $table->addRow([
                ...$row,
                ...($jobWidth > 0 ? [$this->lastJob($summary['lastJob'] ?? null, $jobWidth)] : []),
                ...($errorWidth > 0 ? [$this->lastError($summary['lastError'] ?? null, $errorWidth)] : []),
            ]);
        }

        $table->render();
        $output->writeln('');
        $output->writeln(' <options=bold>'.$this->literal($this->label('feed')).'</>');

        $lines = explode("\n", rtrim($output->fetch(), "\n"));
        $room = $height - count($lines) - 1;

        if ($room > 0) {
            $projectWidth = max(array_map(mb_strwidth(...), [...array_keys($matrix), '']));

            $lines = [...$lines, ...($events === []
                ? [$formatter->format(' <fg=gray>'.$this->literal($this->label('feed_empty')).'</>') ?? '']
                : array_map(fn (MeshEvent $event): string => $this->eventLine($event, $width, $projectWidth, $formatter), array_slice($events, 0, $room)))];
        }

        return implode("\n", array_slice($lines, 0, max(1, $height - 1)));
    }

    /**
     * @param  MeshCell|null  $cell
     */
    private function cell(?array $cell): string
    {
        if ($cell === null) {
            return '<fg=gray>—</>';
        }

        $restarts = '';

        if ($cell['restarts'] > 0) {
            $details = array_filter([
                $cell['lastRestartAt'],
                $cell['state'] === 'running' && $cell['lastExitCode'] !== null ? $this->label('exit_code', ['code' => $cell['lastExitCode']]) : null,
            ]);

            $restarts = " <fg=yellow>↻{$cell['restarts']}".($details === [] ? '' : ' · '.$this->literal(implode(' · ', $details))).'</>';
        }

        return match ($cell['state']) {
            'running' => '<fg=green>●</> '.$this->clock($cell['uptime'] ?? 0)
                .($cell['pid'] !== null ? " <fg=gray>{$cell['pid']}</>" : '')
                .($cell['memoryKb'] !== null ? ' '.$this->megabytes($cell['memoryKb']) : '')
                .$restarts,
            'down' => '<fg=red>✕ '.$this->literal($this->label('exit_code', ['code' => $cell['lastExitCode'] ?? '?'])).'</>'
                .($cell['retryIn'] !== null ? ' <fg=gray>· '.$this->literal($this->label('retry_in', ['seconds' => $cell['retryIn']])).'</>' : '')
                .$restarts,
            'missing' => '<fg=gray>— '.$this->literal($this->label('missing')).'</>',
        };
    }

    /**
     * @param  MeshSummary|null  $summary
     * @return list<string>
     */
    private function counters(?array $summary): array
    {
        if ($summary === null) {
            return ['<fg=gray>—</>', '<fg=gray>—</>', '<fg=gray>—</>'];
        }

        return [
            "<fg=green>{$summary['done']}</>",
            $summary['failed'] > 0 ? "<fg=red;options=bold>{$summary['failed']}</>" : '<fg=gray>0</>',
            number_format($summary['perMinute'], 1),
        ];
    }

    /**
     * Width left for the "last job" and "last error" columns once every other
     * column is measured. Each extra column costs its content plus 3 for
     * padding and border; "last error" goes first when the terminal is too
     * narrow for both.
     *
     * @param  list<list<string>>  $rows  header row first
     * @return array{0: int, 1: int}
     */
    private function textColumnWidths(array $rows, int $width, OutputFormatterInterface $formatter): array
    {
        $columnWidths = [];

        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $columnWidths[$index] = max($columnWidths[$index] ?? 0, Helper::width(Helper::removeDecoration($formatter, $cell)));
            }
        }

        $room = $width - array_sum($columnWidths) - 3 * count($columnWidths) - 1;

        if ($room >= 2 * (self::MIN_TEXT_COLUMN + 3)) {
            $each = intdiv($room, 2) - 3;

            return [$each, $each];
        }

        return $room >= self::MIN_TEXT_COLUMN + 3 ? [$room - 3, 0] : [0, 0];
    }

    private function lastJob(?MeshEvent $event, int $width): string
    {
        if ($event === null) {
            return '<fg=gray>—</>';
        }

        [$mark, $color] = match ($event->status) {
            MeshEvent::FAIL => ['✕', 'red'],
            MeshEvent::SKIPPED => ['↷', 'yellow'],
            default => ['✓', 'green'],
        };

        $label = mb_strimwidth($this->shortLabel($event->label), 0, max(1, $width - Helper::width($event->time) - 3), '…');

        return "<fg=gray>{$event->time}</> ".$this->literal($label)." <fg={$color}>{$mark}</>";
    }

    private function lastError(?MeshEvent $event, int $width): string
    {
        if ($event === null) {
            return '<fg=gray>—</>';
        }

        $label = mb_strimwidth($this->shortLabel($event->label), 0, max(1, $width - Helper::width($event->time) - 1), '…');

        return "<fg=gray>{$event->time}</> <fg=red>".$this->literal($label).'</>';
    }

    /**
     * The label is appended verbatim, never through the formatter: job class
     * names carry backslashes, and `\<` would be read as an escaped tag.
     */
    private function eventLine(MeshEvent $event, int $width, int $projectWidth, OutputFormatterInterface $formatter): string
    {
        [$statusText, $color] = $this->statusStyle($event->status);

        $left = sprintf(' %s  %s  %s  ', $event->time, str_pad($event->project, $projectWidth), str_pad($event->service, 8));
        $right = trim(($event->duration ?? '').' '.$statusText);
        $room = max(8, $width - Helper::width($left) - Helper::width($right) - 3);
        $label = mb_strimwidth($event->label, 0, $room, '…');

        $line = $formatter->format('<fg=gray>'.OutputFormatter::escape($left).'</>').$label;

        if ($right !== '') {
            $dots = str_repeat('.', max(0, $room - Helper::width($label)));
            $duration = $event->duration !== null ? '<fg=gray>'.OutputFormatter::escape($event->duration).'</> ' : '';
            $line .= $formatter->format(" <fg=gray>{$dots}</> {$duration}<fg={$color};options=bold>".OutputFormatter::escape($statusText).'</>');
        }

        return $line;
    }

    /**
     * @return array{0: string, 1: string} status text and colour
     */
    private function statusStyle(string $status): array
    {
        return match ($status) {
            MeshEvent::DONE => ['DONE', 'green'],
            MeshEvent::FAIL => ['FAIL', 'red'],
            MeshEvent::SKIPPED => ['SKIP', 'yellow'],
            MeshEvent::ERROR => [$this->label('status_error'), 'red'],
            MeshEvent::WARN => [$this->label('status_warn'), 'yellow'],
            default => ['', 'default'],
        };
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private function label(string $key, array $replace = []): string
    {
        $text = $this->labels[$key] ?? $key;

        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    /**
     * `App\Jobs\SyncUsersJob` → `SyncUsersJob`; anything that is not a bare
     * class name (a command, an error message) stays as is.
     */
    private function shortLabel(string $label): string
    {
        return preg_match('/^[A-Za-z_][\w\\\\]*\\\\(?<class>\w+)$/', $label, $match) === 1 ? $match['class'] : $label;
    }

    /**
     * Text for a table cell, which always goes through the formatter. Every
     * `<` is escaped — Symfony's own escape skips one preceded by a backslash
     * — and a trailing backslash must not swallow the closing tag after it.
     */
    private function literal(string $text): string
    {
        return OutputFormatter::escapeTrailingBackslash(str_replace('<', '\\<', $text));
    }

    private function megabytes(int $kilobytes): string
    {
        return sprintf('%d MB', (int) round($kilobytes / 1024));
    }

    private function clock(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
