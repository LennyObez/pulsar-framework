<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function bin2hex;
use function chmod;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function rtrim;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * A disposable directory to plant a defect in.
 *
 * Gate tests work by building an unhealthy tree and pointing the gate at it, so
 * they all need the same three things: somewhere outside the repository to build
 * it, a way to write files into it, and a removal that actually removes.
 *
 * The removal is the part worth writing once. Several of these gates run a tool
 * that leaves its own files behind — `bin/pulsar` generates an environment file
 * in whatever directory it is run from — so deleting only what the test wrote
 * would leave litter on every machine that ever ran the suite. A verification
 * artefact left behind is an incident of its own.
 */
final class TemporaryTree
{
    private function __construct(public readonly string $path) {}

    public static function create(string $label): self
    {
        $path = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR . 'pulsar-gate-' . $label . '-' . bin2hex(random_bytes(6));

        if (!mkdir($path, 0o700, true) && !is_dir($path)) {
            throw new RuntimeException('could not create the temporary tree at ' . $path);
        }

        return new self($path);
    }

    /**
     * Write a file into the tree, creating parent directories as needed.
     */
    public function write(string $relativePath, string $contents): void
    {
        $target = $this->path . DIRECTORY_SEPARATOR . $relativePath;
        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('could not create ' . $directory);
        }

        if (file_put_contents($target, $contents) === false) {
            throw new RuntimeException('could not write ' . $target);
        }
    }

    /**
     * Remove the tree and everything anything else put in it.
     */
    public function remove(): void
    {
        if (!is_dir($this->path)) {
            return;
        }

        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());

                continue;
            }

            // Some tools leave read-only files behind; unlink refuses those on
            // Windows, and a refusal here would leak the whole tree.
            chmod($entry->getPathname(), 0o600);
            unlink($entry->getPathname());
        }

        rmdir($this->path);
    }
}
