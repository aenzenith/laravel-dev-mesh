<?php

namespace Aenzenith\DevMesh;

/**
 * Running totals of one project since the dashboard started, counted from
 * the feed events of all its services.
 */
final class MeshProjectStats
{
    private const RATE_WINDOW = 300;

    private const MIN_RATE_WINDOW = 60;

    public int $done = 0;

    public int $failed = 0;

    public ?MeshEvent $lastJob = null;

    public ?MeshEvent $lastError = null;

    /** @var list<float> */
    private array $finishedAt = [];

    public function record(MeshEvent $event, float $now): void
    {
        if (in_array($event->status, [MeshEvent::DONE, MeshEvent::FAIL, MeshEvent::SKIPPED], true)) {
            $this->lastJob = $event;
        }

        if (in_array($event->status, [MeshEvent::DONE, MeshEvent::FAIL], true)) {
            $this->finishedAt[] = $now;
        }

        if ($event->status === MeshEvent::DONE) {
            $this->done++;
        }

        if (in_array($event->status, [MeshEvent::FAIL, MeshEvent::ERROR], true)) {
            $this->failed++;
            $this->lastError = $event;
        }
    }

    /**
     * Finished jobs and tasks per minute over the last five minutes; a mesh
     * younger than a minute is measured against a full minute so the first
     * job does not read as a spike.
     */
    public function perMinute(float $now, float $meshStartedAt): float
    {
        $this->finishedAt = array_values(array_filter($this->finishedAt, fn (float $at): bool => $at >= $now - self::RATE_WINDOW));
        $window = min(self::RATE_WINDOW, max(self::MIN_RATE_WINDOW, $now - $meshStartedAt));

        return count($this->finishedAt) * 60 / $window;
    }
}
