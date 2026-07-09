<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\SharedMemoryConfigStore;
use ReflectionClass;
use RuntimeException;

use function array_fill_keys;
use function array_filter;
use function array_unique;
use function array_values;
use function class_exists;
use function dirname;
use function enum_exists;
use function extension_loaded;
use function implode;
use function preg_match_all;
use function serialize;
use function sort;
use function unserialize;

use const DIRECTORY_SEPARATOR;

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

    /**
     * Drift guard (needs no ext-shmop): read()'s allowlist is deliberately tight
     * for defense-in-depth (only config value objects, NOT the broad
     * {@see \Pulsar\Cache\CacheAllowedClasses} scan), which means it can silently
     * fall out of sync with the config graph. Build the framework's real config,
     * serialize it exactly as write() does, and assert every class/enum in the
     * serialized bytes is allow-listed — so a new config DTO or nested value
     * object that is not allow-listed becomes a build failure here instead of a
     * __PHP_Incomplete_Class TypeError in a shared-memory worker at runtime.
     */
    #[Test]
    public function deserializationAllowlistCoversTheSerializedConfigGraph(): void
    {
        $manager = new ConfigManager(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config');
        $manager->load();

        $serialized = serialize($manager->repository());

        // PHP emits O:len:"Class" for objects and E:len:"Enum:case" for enums;
        // both stop the class token at the closing quote or the enum ':' separator.
        preg_match_all('/(?:O|E):\d+:"([^":]+)/', $serialized, $matches);

        $allowed = array_fill_keys($this->deserializationAllowlist(), true);

        $missing = array_values(array_unique(array_filter(
            $matches[1],
            static fn(string $class): bool =>
                (class_exists($class) || enum_exists($class)) && !isset($allowed[$class]),
        )));
        sort($missing);

        self::assertSame(
            [],
            $missing,
            "SharedMemoryConfigStore::DESERIALIZATION_ALLOWLIST does not cover the serialized config graph.\n"
            . "These classes appear in a serialized ConfigRepository but are not allow-listed, so read()\n"
            . "would fail in a shared-memory worker. Add them:\n- " . implode("\n- ", $missing),
        );

        // Prove the allowlist actually round-trips the real graph with no loss
        // (catches any class silently replaced by __PHP_Incomplete_Class).
        /** @var mixed $restored */
        $restored = unserialize($serialized, ['allowed_classes' => $this->deserializationAllowlist()]);
        self::assertInstanceOf(ConfigRepository::class, $restored);
        self::assertSame($serialized, serialize($restored));
    }

    /**
     * @return list<class-string>
     */
    private function deserializationAllowlist(): array
    {
        /** @var mixed $value */
        $value = new ReflectionClass(SharedMemoryConfigStore::class)->getConstant('DESERIALIZATION_ALLOWLIST');

        self::assertIsArray($value);

        /** @var list<class-string> $value */
        return $value;
    }
}
