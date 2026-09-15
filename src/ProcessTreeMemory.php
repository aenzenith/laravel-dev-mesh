<?php

namespace Aenzenith\DevMesh;

/**
 * Resident memory of a process together with all of its descendants.
 * Horizon's master is small on its own; the supervisors and queue workers it
 * spawns hold the real memory, so they are summed into the master's figure.
 */
final class ProcessTreeMemory
{
    /**
     * @param  list<int>  $roots
     * @return array<int, int> root pid => resident memory in KB
     */
    public static function sample(array $roots): array
    {
        if ($roots === []) {
            return [];
        }

        return self::fromPsOutput((string) shell_exec('ps -A -o pid=,ppid=,rss='), $roots);
    }

    /**
     * @param  string  $psOutput  `ps -A -o pid=,ppid=,rss=` lines
     * @param  list<int>  $roots
     * @return array<int, int> root pid => resident memory in KB
     */
    public static function fromPsOutput(string $psOutput, array $roots): array
    {
        $residentKb = [];
        $children = [];

        foreach (preg_split('/\R/', $psOutput) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s*$/', $line, $match) !== 1) {
                continue;
            }

            $residentKb[(int) $match[1]] = (int) $match[3];
            $children[(int) $match[2]][] = (int) $match[1];
        }

        $totals = [];

        foreach ($roots as $root) {
            if (! isset($residentKb[$root])) {
                continue;
            }

            $total = 0;
            $pending = [$root];
            $seen = [];

            while ($pending !== []) {
                $pid = array_pop($pending);

                if (isset($seen[$pid])) {
                    continue;
                }

                $seen[$pid] = true;
                $total += $residentKb[$pid] ?? 0;
                array_push($pending, ...($children[$pid] ?? []));
            }

            $totals[$root] = $total;
        }

        return $totals;
    }
}
