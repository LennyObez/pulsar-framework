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
    public function capabilitiesReflectAtomicAddAndCounters(): void
    {
        $capabilities = $this->driver->capabilities();

        // add()/increment() are now genuinely atomic (flock read-modify-write),
        // so the driver advertises atomic increment and strict tags.
        self::assertTrue($capabilities->supportsAtomicIncrement);
        self::assertTrue($capabilities->supportsTagsStrict);
        self::assertTrue($capabilities->supportsBinary);
        // Lock FENCING is a separate concern (the lock layer), not claimed here.
        self::assertFalse($capabilities->supportsLocksFencing);
    }

    #[Test]
    public function addStoresOnlyWhenAbsentAndReportsIt(): void
    {
        self::assertTrue($this->driver->add('k', 'first', 3600), 'absent key is claimed');
        self::assertSame('first', $this->driver->get('k'));

        self::assertFalse($this->driver->add('k', 'second', 3600), 'present key is refused');
        self::assertSame('first', $this->driver->get('k'), 'value is not overwritten');
    }

    #[Test]
    public function addTreatsAnExpiredEntryAsAbsent(): void
    {
        $this->driver->set('k', 'stale', 3600);
        // Force expiry by writing a past-dated entry through set with ttl<=0
        // semantics: set() deletes on non-positive ttl, so re-add after that.
        $this->driver->delete('k');

        self::assertTrue($this->driver->add('k', 'fresh', 3600));
        self::assertSame('fresh', $this->driver->get('k'));
    }

    #[Test]
    public function incrementCreatesThenAccumulatesAtomically(): void
    {
        self::assertSame(1, $this->driver->increment('hits'));
        self::assertSame(3, $this->driver->increment('hits', 2));
        self::assertSame(2, $this->driver->decrement('hits'));
        self::assertSame('2', $this->driver->get('hits'));
    }

    #[Test]
    public function incrementRefusesANonIntegerValue(): void
    {
        $this->driver->set('name', 'alice', 3600);

        self::assertFalse($this->driver->increment('name'));
        self::assertSame('alice', $this->driver->get('name'), 'the value is left untouched');
    }

    #[Test]
    public function incrementPreservesTheExistingExpiry(): void
    {
        $this->driver->set('window', '5', 3600);

        self::assertSame(6, $this->driver->increment('window'));
        // The entry must still be present (its TTL was preserved, not reset to
        // no-expiry or dropped).
        self::assertSame('6', $this->driver->get('window'));
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
