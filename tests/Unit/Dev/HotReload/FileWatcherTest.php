<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Dev\HotReload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Dev\HotReload\FileChangeEvent;
use Pulsar\Dev\HotReload\FileChangeType;
use Pulsar\Dev\HotReload\FileWatcher;
use Pulsar\WebSocket\BroadcastManagerInterface;

use function file_put_contents;
use function function_exists;
use function mkdir;
use function sys_get_temp_dir;
use function touch;
use function unlink;

#[CoversClass(FileWatcher::class)]
final class FileWatcherTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pulsar_watcher_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function buildIndexScansPhpFiles(): void
    {
        file_put_contents($this->tempDir . '/Controller.php', '<?php echo 1;');
        file_put_contents($this->tempDir . '/view.pulse', '<div>Hello</div>');
        file_put_contents($this->tempDir . '/readme.txt', 'Not watched');

        $watcher = new FileWatcher([$this->tempDir]);
        $count = $watcher->buildIndex();

        // Should index .php and .pulse but not .txt
        self::assertSame(2, $count);
    }

    #[Test]
    public function buildIndexReturnsZeroForEmptyDirectory(): void
    {
        $watcher = new FileWatcher([$this->tempDir]);
        $count = $watcher->buildIndex();

        self::assertSame(0, $count);
    }

    #[Test]
    public function detectChangesFindsNewFiles(): void
    {
        $watcher = new FileWatcher([$this->tempDir]);
        $watcher->buildIndex();

        // Create a new file after indexing
        file_put_contents($this->tempDir . '/NewController.php', '<?php class NewController {}');

        $changes = $watcher->detectChanges();

        self::assertNotEmpty($changes);
        self::assertContainsOnlyInstancesOf(FileChangeEvent::class, $changes);

        $createdEvents = array_filter(
            $changes,
            static fn(FileChangeEvent $e): bool => $e->type === FileChangeType::Created,
        );
        self::assertNotEmpty($createdEvents);
    }

    #[Test]
    public function detectChangesFindsModifiedFiles(): void
    {
        $filePath = $this->tempDir . '/Existing.php';
        file_put_contents($filePath, '<?php // v1');

        $watcher = new FileWatcher([$this->tempDir]);
        $watcher->buildIndex();

        // Modify the file with a future mtime to guarantee detection
        file_put_contents($filePath, '<?php // v2');
        touch($filePath, time() + 10);

        $changes = $watcher->detectChanges();

        $modifiedEvents = array_filter(
            $changes,
            static fn(FileChangeEvent $e): bool => $e->type === FileChangeType::Modified,
        );
        self::assertNotEmpty($modifiedEvents);
    }

    #[Test]
    public function detectChangesFindsDeletedFiles(): void
    {
        $filePath = $this->tempDir . '/ToDelete.php';
        file_put_contents($filePath, '<?php // will be deleted');

        $watcher = new FileWatcher([$this->tempDir]);
        $watcher->buildIndex();

        unlink($filePath);

        $changes = $watcher->detectChanges();

        $deletedEvents = array_filter(
            $changes,
            static fn(FileChangeEvent $e): bool => $e->type === FileChangeType::Deleted,
        );
        self::assertNotEmpty($deletedEvents);
    }

    #[Test]
    public function detectChangesReturnsEmptyWhenNothingChanged(): void
    {
        file_put_contents($this->tempDir . '/Stable.php', '<?php // stable');

        $watcher = new FileWatcher([$this->tempDir]);
        $watcher->buildIndex();

        $changes = $watcher->detectChanges();

        self::assertSame([], $changes);
    }

    #[Test]
    public function detectChangesBroadcastsViaBroadcaster(): void
    {
        $broadcaster = $this->createStub(BroadcastManagerInterface::class);

        $watcher = new FileWatcher(
            directories: [$this->tempDir],
            broadcaster: $broadcaster,
        );
        $watcher->buildIndex();

        file_put_contents($this->tempDir . '/Broadcast.php', '<?php // new');

        // Should not throw even when broadcaster is present
        $changes = $watcher->detectChanges();
        self::assertNotEmpty($changes);
    }

    #[Test]
    public function isWatchableExtensionReturnsTrueForWatchedTypes(): void
    {
        self::assertTrue(FileWatcher::isWatchableExtension('Controller.php'));
        self::assertTrue(FileWatcher::isWatchableExtension('layout.pulse'));
        self::assertTrue(FileWatcher::isWatchableExtension('styles.css'));
        self::assertTrue(FileWatcher::isWatchableExtension('app.js'));
    }

    #[Test]
    public function isWatchableExtensionReturnsFalseForNonWatchedTypes(): void
    {
        self::assertFalse(FileWatcher::isWatchableExtension('readme.txt'));
        self::assertFalse(FileWatcher::isWatchableExtension('data.json'));
        self::assertFalse(FileWatcher::isWatchableExtension('image.png'));
        self::assertFalse(FileWatcher::isWatchableExtension('config.yml'));
    }

    #[Test]
    public function hasInotifyReturnsBool(): void
    {
        // inotify is a Linux-only extension; verify the method runs without error
        $result = FileWatcher::hasInotify();
        self::assertSame(function_exists('inotify_init'), $result);
    }

    #[Test]
    public function stopSetsRunningToFalse(): void
    {
        $watcher = new FileWatcher([$this->tempDir]);

        self::assertFalse($watcher->isRunning());
        $watcher->stop();
        self::assertFalse($watcher->isRunning());
    }

    #[Test]
    public function indexedFileCountReflectsCurrentIndex(): void
    {
        $watcher = new FileWatcher([$this->tempDir]);
        self::assertSame(0, $watcher->indexedFileCount());

        file_put_contents($this->tempDir . '/A.php', '<?php');
        file_put_contents($this->tempDir . '/B.php', '<?php');

        $watcher->buildIndex();
        self::assertSame(2, $watcher->indexedFileCount());
    }

    #[Test]
    public function getFileIndexReturnsPathToMtimeMap(): void
    {
        file_put_contents($this->tempDir . '/Test.php', '<?php');

        $watcher = new FileWatcher([$this->tempDir]);
        $watcher->buildIndex();

        $index = $watcher->getFileIndex();
        self::assertNotEmpty($index);

        foreach ($index as $path => $mtime) {
            self::assertIsString($path);
            self::assertIsInt($mtime);
            self::assertGreaterThan(0, $mtime);
        }
    }

    #[Test]
    public function multipleDirectoriesAreAllScanned(): void
    {
        $dir2 = $this->tempDir . '/subdir';
        mkdir($dir2, 0o755, true);

        file_put_contents($this->tempDir . '/Root.php', '<?php');
        file_put_contents($dir2 . '/Sub.php', '<?php');

        $watcher = new FileWatcher([$this->tempDir, $dir2]);
        $count = $watcher->buildIndex();

        // Both directories contribute files (root has Root.php + subdir/Sub.php via glob,
        // dir2 explicitly adds Sub.php)
        self::assertGreaterThanOrEqual(2, $count);
    }

    #[Test]
    public function detectChangesIgnoresNonWatchableExtensions(): void
    {
        $watcher = new FileWatcher([$this->tempDir]);
        $watcher->buildIndex();

        // Create a non-watchable file
        file_put_contents($this->tempDir . '/data.json', '{}');

        $changes = $watcher->detectChanges();
        self::assertSame([], $changes);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
