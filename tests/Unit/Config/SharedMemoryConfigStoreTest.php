<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\SharedMemoryConfigStore;
use RuntimeException;

use function extension_loaded;

#[CoversClass(SharedMemoryConfigStore::class)]
final class SharedMemoryConfigStoreTest extends TestCase
{
    #[Test]
    public function constructorRequiresShmopExtension(): void
    {
        if (extension_loaded('shmop')) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ext-shmop is required');

        new SharedMemoryConfigStore(hmacKey: 'test-key');
    }

    #[Test]
    public function constructorRejectsEmptyHmacKey(): void
    {
        if (!extension_loaded('shmop')) {
            self::markTestSkipped('ext-shmop not available');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HMAC key must not be empty');

        new SharedMemoryConfigStore(hmacKey: '');
    }

    #[Test]
    public function readReturnsNullWhenNoSegmentExists(): void
    {
        if (!extension_loaded('shmop')) {
            self::markTestSkipped('ext-shmop not available');
        }

        $store = new SharedMemoryConfigStore(
            hmacKey: 'test-secret-key',
            projectId: 'test_nonexistent_' . uniqid(),
        );

        self::assertNull($store->read());
        self::assertFalse($store->exists());
    }

    #[Test]
    public function deleteIsIdempotentOnMissingSegment(): void
    {
        if (!extension_loaded('shmop')) {
            self::markTestSkipped('ext-shmop not available');
        }

        $store = new SharedMemoryConfigStore(
            hmacKey: 'test-secret-key',
            projectId: 'test_delete_' . uniqid(),
        );

        // Should not throw
        $store->delete();
        $this->addToAssertionCount(1);
    }
}
