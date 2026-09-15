<?php

namespace Aenzenith\DevMesh;

/**
 * Turns raw stdout/stderr lines of queue workers (Horizon included) and
 * `schedule:work` into feed events. Both print finished work through
 * Laravel's console components as `Y-m-d H:i:s <label> ....... <runtime>
 * DONE|FAIL`; the RUNNING half of a queue job is dropped so each job shows
 * up once.
 */
final class MeshOutputParser
{
    private const TASK_LINE = '/^\d{4}-\d{2}-\d{2} (?<time>\d{2}:\d{2}:\d{2})\s+(?<label>.+?)(?:\s+(?<duration>\d+(?:\.\d+)?\s?(?:ms|s|m)(?:\s\d+(?:\.\d+)?\s?(?:ms|s))?))?\s+(?<status>RUNNING|DONE|FAIL|SKIPPED)$/u';

    /** @var list<string> */
    private const NOISE = [
        'No scheduled commands are ready to run',
        'Running scheduled tasks',
        'Press Ctrl+C to stop',
    ];

    /** @var array<string, string> */
    private const TASK_STATUS = [
        'DONE' => MeshEvent::DONE,
        'FAIL' => MeshEvent::FAIL,
        'SKIPPED' => MeshEvent::SKIPPED,
    ];

    /**
     * @param  string  $backgroundSuffix  appended to scheduled commands that run in the background
     */
    public function __construct(private readonly string $backgroundSuffix = '(background)') {}

    public function parse(string $project, string $service, string $line, string $fallbackTime): ?MeshEvent
    {
        $text = trim((string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line));
        $text = trim((string) preg_replace('/\s\.{2,}(?=\s|$)/', '', $text));

        if ($text === '' || $this->isNoise($text)) {
            return null;
        }

        if (preg_match(self::TASK_LINE, $text, $match) === 1) {
            if ($match['status'] === 'RUNNING') {
                return null;
            }

            return new MeshEvent(
                time: $match['time'],
                project: $project,
                service: $service,
                label: $this->cleanLabel($match['label']),
                status: self::TASK_STATUS[$match['status']],
                duration: $match['duration'] !== '' ? $match['duration'] : null,
            );
        }

        if (preg_match('/^(?<level>INFO|WARN|WARNING|ERROR)\s+(?<message>.+)$/', $text, $match) === 1) {
            $status = match ($match['level']) {
                'INFO' => MeshEvent::INFO,
                'ERROR' => MeshEvent::ERROR,
                default => MeshEvent::WARN,
            };

            return new MeshEvent($fallbackTime, $project, $service, $match['message'], $status);
        }

        $status = preg_match('/exception|error|fatal/i', $text) === 1 ? MeshEvent::ERROR : MeshEvent::INFO;

        return new MeshEvent($fallbackTime, $project, $service, $text, $status);
    }

    /**
     * Idle scheduler ticks and stack-trace continuation lines would bury the
     * finished work, so they never reach the feed.
     */
    private function isNoise(string $text): bool
    {
        foreach (self::NOISE as $noise) {
            if (str_contains($text, $noise)) {
                return true;
            }
        }

        return preg_match('/^(#\d+\s|at\s\/|\+\d+ vendor frames|\d+\s+(vendor|app)\/|[\s.─│┌┐└┘]+$)/u', $text) === 1;
    }

    /**
     * `Running ['artisan' reports:rollup]` → `reports:rollup`.
     */
    private function cleanLabel(string $label): string
    {
        if (preg_match('/^Running \[(?<command>.+)\](?<background> in background)?$/', $label, $match) !== 1) {
            return $label;
        }

        $command = (string) preg_replace('/^[\'"]?artisan[\'"]?\s+/', '', $match['command']);

        return ($match['background'] ?? '') !== '' ? "{$command} {$this->backgroundSuffix}" : $command;
    }
}
