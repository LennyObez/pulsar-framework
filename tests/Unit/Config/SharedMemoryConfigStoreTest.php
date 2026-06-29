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
    /**
     * A 32-byte key, matching the framework's libsodium subkey size and the
     * NIST SP 800-107 minimum for HMAC-SHA256.
     */
    private const string VALID_KEY = 'test-secret-key-0123456789abcdef';

    #[Test]
    public function constructorRequiresShmopExtension(): void
    {
        if (extension_loaded('shmop')) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ext-shmop is required');

        new SharedMemoryConfigStore(hmacKey: self::VALID_KEY);
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
    public function constructorRejectsShortHmacKey(): void
    {
        if (!extension_loaded('shmop')) {
            self::markTestSkipped('ext-shmop not available');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HMAC key must be at least 32 bytes');

        // 31 bytes: one short of the HMAC-SHA256 minimum.
        new SharedMemoryConfigStore(hmacKey: 'short-key-0123456789abcdefghij');
    }

    #[Test]
    public function readReturnsNullWhenNoSegmentExists(): void
    {
        if (!extension_loaded('shmop')) {
            self::markTestSkipped('ext-shmop not available');
        }

        $store = new SharedMemoryConfigStore(
            hmacKey: self::VALID_KEY,
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
            hmacKey: self::VALID_KEY,
            projectId: 'test_delete_' . uniqid(),
        );

        // Should not throw
        $store->delete();
        $this->addToAssertionCount(1);
    }
}
