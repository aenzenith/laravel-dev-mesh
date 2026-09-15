<?php

namespace Aenzenith\DevMesh;

/**
 * One row of the dashboard's activity feed: a finished queue job or
 * scheduled task, or a notable raw line printed by a mesh child process.
 */
final readonly class MeshEvent
{
    public const DONE = 'done';

    public const FAIL = 'fail';

    public const SKIPPED = 'skipped';

    public const ERROR = 'error';

    public const WARN = 'warn';

    public const INFO = 'info';

    public function __construct(
        public string $time,
        public string $project,
        public string $service,
        public string $label,
        public string $status,
        public ?string $duration = null,
    ) {}
}
