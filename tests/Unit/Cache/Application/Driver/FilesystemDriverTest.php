<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Driver\FilesystemDriver;

use function hash;
use function is_dir;
use function is_file;
use function substr;
use function sys_get_temp_dir;
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
