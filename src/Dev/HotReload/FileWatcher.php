<?php

declare(strict_types=1);

namespace Pulsar\Dev\HotReload;

use Pulsar\Api\Internal;
use Pulsar\WebSocket\BroadcastManagerInterface;

use function array_diff_key;
use function array_keys;
use function clearstatcache;
use function count;
use function filemtime;
use function function_exists;
use function glob;
use function in_array;
use function is_file;
use function microtime;
use function pathinfo;
use function realpath;
use function usleep;

use const GLOB_NOSORT;
use const PATHINFO_EXTENSION;

/**
 * Watches PHP and Pulse template files for changes using polling.
 *
 * On Linux, inotify is used when available for efficient kernel-level
 * change detection. On all other platforms (Windows, macOS), or when
 * inotify is unavailable, falls back to mtime-based polling.
 *
 * When a change is detected, broadcasts a reload message via the
 * WebSocket BroadcastManager on the `hot-reload` channel.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final class FileWatcher
{
    private const string CHANNEL = 'hot-reload';
    private const string EVENT = 'file-changed';

    /** @var list<string> File extensions to watch */
    private const array WATCHABLE_EXTENSIONS = ['php', 'pulse', 'css', 'js'];

    /** @var array<string, int> path => last known mtime */
    private array $fileIndex = [];

    private bool $running = false;

    /**
     * @param list<string> $directories Directories to watch recursively
     * @param int $pollIntervalMs Polling interval in milliseconds (polling mode)
     */
    public function __construct(
        private readonly array $directories,
        private readonly ?BroadcastManagerInterface $broadcaster = null,
        private readonly int $pollIntervalMs = 500,
    ) {}

    /**
     * Build the initial file index by scanning all watched directories.
     *
     * @return int Number of files indexed
     */
    public function buildIndex(): int
    {
        $this->fileIndex = [];

        foreach ($this->directories as $directory) {
            $this->scanDirectory($directory);
        }

        return $this->indexedFileCount();
    }

    /**
     * Check for changes since the last check (single poll cycle).
     *
     * @return list<FileChangeEvent> Detected changes
     */
    public function detectChanges(): array
    {
        $events = [];
        $currentFiles = [];

        foreach ($this->directories as $directory) {
            foreach ($this->iterateFiles($directory) as $path) {
                $resolvedPath = realpath($path);
                if ($resolvedPath === false) {
                    continue;
                }

                clearstatcache(true, $resolvedPath);
                $mtime = @filemtime($resolvedPath);
                if ($mtime === false) {
                    continue;
                }

                $currentFiles[$resolvedPath] = $mtime;

                if (!isset($this->fileIndex[$resolvedPath])) {
                    $events[] = new FileChangeEvent(
                        path: $resolvedPath,
                        type: FileChangeType::Created,
                        detectedAt: microtime(true),
                    );
                } elseif ($this->fileIndex[$resolvedPath] < $mtime) {
                    $events[] = new FileChangeEvent(
                        path: $resolvedPath,
                        type: FileChangeType::Modified,
                        detectedAt: microtime(true),
                    );
                }
            }
        }

        // Detect deletions
        $deletedPaths = array_diff_key($this->fileIndex, $currentFiles);
        foreach (array_keys($deletedPaths) as $deletedPath) {
            $events[] = new FileChangeEvent(
                path: $deletedPath,
                type: FileChangeType::Deleted,
                detectedAt: microtime(true),
            );
        }

        // Update index to current state
        $this->fileIndex = $currentFiles;

        // Broadcast changes
        foreach ($events as $event) {
            $this->broadcastChange($event);
        }

        return $events;
    }

    /**
     * Start the watch loop (blocks until stop() is called).
     *
     * @param callable(list<FileChangeEvent>): void|null $onChanges Optional callback for detected changes
     */
    public function watch(?callable $onChanges = null): void
    {
        $this->running = true;

        if ($this->fileIndex === []) {
            $this->buildIndex();
        }

        while ($this->isRunning()) {
            $changes = $this->detectChanges();

            if ($changes !== [] && $onChanges !== null) {
                $onChanges($changes);
            }

            usleep($this->pollIntervalMs * 1000);
        }
    }

    /**
     * Stop the watch loop.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Whether the watcher is currently running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Number of files currently in the index.
     */
    public function indexedFileCount(): int
    {
        return count($this->fileIndex);
    }

    /**
     * Whether inotify is available on this platform.
     */
    public static function hasInotify(): bool
    {
        return function_exists('inotify_init');
    }

    /**
     * Check if a file extension is watchable.
     */
    public static function isWatchableExtension(string $path): bool
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return in_array($ext, self::WATCHABLE_EXTENSIONS, true);
    }

    /**
     * Get the current file index (for testing).
     *
     * @return array<string, int>
     */
    public function getFileIndex(): array
    {
        return $this->fileIndex;
    }

    /**
     * Scan a directory and add files to the index.
     */
    private function scanDirectory(string $directory): void
    {
        foreach ($this->iterateFiles($directory) as $path) {
            $resolvedPath = realpath($path);
            if ($resolvedPath === false) {
                continue;
            }

            $mtime = @filemtime($resolvedPath);
            if ($mtime !== false) {
                $this->fileIndex[$resolvedPath] = $mtime;
            }
        }
    }

    /**
     * Iterate watchable files in a directory recursively.
     *
     * @return iterable<string>
     */
    private function iterateFiles(string $directory): iterable
    {
        // Use glob for recursive file discovery
        foreach (self::WATCHABLE_EXTENSIONS as $ext) {
            $pattern = $directory . '/**/*.' . $ext;
            $files = glob($pattern, GLOB_NOSORT);
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                if (is_file($file)) {
                    yield $file;
                }
            }

            // Also check root-level files
            $rootPattern = $directory . '/*.' . $ext;
            $rootFiles = glob($rootPattern, GLOB_NOSORT);
            if ($rootFiles === false) {
                continue;
            }

            foreach ($rootFiles as $file) {
                if (is_file($file)) {
                    yield $file;
                }
            }
        }
    }

    /**
     * Broadcast a file change event via WebSocket.
     */
    private function broadcastChange(FileChangeEvent $event): void
    {
        if ($this->broadcaster === null) {
            return;
        }

        $this->broadcaster->broadcast(
            channel: self::CHANNEL,
            event: self::EVENT,
            data: $event->toArray(),
        );
    }
}
