<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Driver\FilesystemDriver;

use function file_put_contents;
use function hash;
use function hrtime;
use function is_dir;
use function is_file;
use function substr;
use function sys_get_temp_dir;
use function time;
use function uniqid;

use const DIRECTORY_SEPARATOR;

#[CoversClass(FilesystemDriver::class)]
final class FilesystemDriverTest extends TestCase
{
    use CacheDriverContractTrait;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pulsar_test_cache_' . uniqid('', true);
        $this->driver = $this->createDriver();
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->directory);
    }

    protected function createDriver(): CacheDriverInterface
    {
        return new FilesystemDriver($this->directory);
    }

    #[Test]
    public function filesAreCreatedInShardedDirectories(): void
    {
        $this->driver->set('test-key', 'value', 3600);

        $keyHash = hash('xxh128', 'test-key');
        $shard = substr($keyHash, 0, 2);
        $expectedPath = $this->directory . DIRECTORY_SEPARATOR . $shard . DIRECTORY_SEPARATOR . $keyHash;

        self::assertTrue(is_dir($this->directory . DIRECTORY_SEPARATOR . $shard));
        self::assertTrue(is_file($expectedPath));
    }

    #[Test]
    public function gcReclaimsExpiredEntriesThatNoReadWouldTouch(): void
    {
        // gcDivisor: 0 disables the write-time lottery so this test is deterministic.
        $driver = new FilesystemDriver($this->directory, gcDivisor: 0);
        $driver->set('fresh', 'keep', 3600);
        $driver->set('stale', 'drop', 10);

        // Sweep as if 20s elapsed: the ttl-10 entry has expired, the ttl-3600 has not.
        $removed = $driver->gc(now: time() + 20);

        self::assertSame(1, $removed);
        self::assertNull($driver->get('stale'));
        self::assertSame('keep', $driver->get('fresh'));
    }

    #[Test]
    public function gcLeavesEntriesWithoutAnExpiryUntouched(): void
    {
        $driver = new FilesystemDriver($this->directory, gcDivisor: 0);
        $driver->set('eternal', 'v', null);

        self::assertSame(0, $driver->gc(now: time() + 100_000));
        self::assertSame('v', $driver->get('eternal'));
    }

    #[Test]
    public function atomicWriteSurvivesOverwrite(): void
    {
        $this->driver->set('key', 'first', 3600);
        $this->driver->set('key', 'second', 3600);

        self::assertSame('second', $this->driver->get('key'));
    }

    #[Test]
    public function capabilitiesSupportsBinaryOnly(): void
    {
        $capabilities = $this->driver->capabilities();

        self::assertFalse($capabilities->supportsTagsStrict);
        self::assertFalse($capabilities->supportsLocksFencing);
        self::assertTrue($capabilities->supportsBinary);
        self::assertFalse($capabilities->supportsAtomicIncrement);
    }

    #[Test]
    public function nameReturnsFilesystem(): void
    {
        self::assertSame('filesystem', $this->driver->name());
    }

    #[Test]
    public function clearRemovesOrphanedTempFiles(): void
    {
        // Create a real sharded entry so the shard directory exists.
        $this->driver->set('test-key', 'value', 3600);

        $shard = substr(hash('xxh128', 'test-key'), 0, 2);
        $shardDir = $this->directory . DIRECTORY_SEPARATOR . $shard;

        // Plant an orphaned atomic-write temp file (a leftover from an
        // interrupted set()). glob('*') does not match this leading-dot file
        // on Unix, so without the explicit .tmp.* sweep it would survive
        // clear() and leak.
        $orphan = $shardDir . DIRECTORY_SEPARATOR . '.tmp.999.' . hrtime(true);
        file_put_contents($orphan, 'leftover');
        self::assertTrue(is_file($orphan));

        $this->driver->clear();

        self::assertFalse(is_file($orphan));
        self::assertFalse(is_dir($shardDir));
    }

    private function recursiveDelete(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($full)) {
                $this->recursiveDelete($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}
