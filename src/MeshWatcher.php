<?php

namespace Aenzenith\DevMesh;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Notices code changes in one project by polling modification times, so the
 * workers that keep the application in memory (Horizon, `queue:work`) can be
 * restarted. Directories are scanned for `.php` files only: a SQLite
 * database or a log written next to the code must never restart anything.
 * Files listed by name are watched whatever they hold (`.env`).
 */
final class MeshWatcher
{
    /** @var array<string, string> relative path => modification time and size */
    private array $files;

    private ?string $pending = null;

    /**
     * @param  list<string>  $paths  directories and files, relative to $root
     */
    public function __construct(
        private readonly string $root,
        private readonly array $paths,
    ) {
        $this->files = $this->scan();
    }

    /**
     * The first file changed since the last call, reported once the tree has
     * settled: a burst of saves or a branch checkout spans several scans and
     * must restart the workers once, after the last write.
     */
    public function changed(): ?string
    {
        $files = $this->scan();
        $changed = array_key_first(array_diff_assoc($files, $this->files) + array_diff_key($this->files, $files));
        $this->files = $files;

        if ($changed !== null) {
            $this->pending ??= (string) $changed;

            return null;
        }

        [$settled, $this->pending] = [$this->pending, null];

        return $settled;
    }

    /**
     * @return array<string, string>
     */
    private function scan(): array
    {
        clearstatcache();

        $files = [];

        foreach ($this->paths as $path) {
            $absolute = $this->root.DIRECTORY_SEPARATOR.$path;

            if (is_file($absolute)) {
                $files[$path] = $this->fingerprint(new SplFileInfo($absolute));

                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            try {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));

                foreach ($iterator as $file) {
                    if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                        $files[substr($file->getPathname(), strlen($this->root) + 1)] = $this->fingerprint($file);
                    }
                }
            } catch (RuntimeException) {
                // A directory vanished mid-scan (a branch checkout): the
                // partial result reads as a change and the next scan settles it.
            }
        }

        return $files;
    }

    private function fingerprint(SplFileInfo $file): string
    {
        try {
            return $file->getMTime().':'.$file->getSize();
        } catch (RuntimeException) {
            return 'gone';
        }
    }
}
