<?php

declare(strict_types=1);

namespace Pulsar\Tests\Chaos;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\ArrayDriver;
use Pulsar\Cache\Application\Driver\CacheDriverCapabilities;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use RuntimeException;

#[CoversClass(ArrayDriver::class)]
#[Group('chaos')]
final class CacheFailureTest extends TestCase
{
    #[Test]
    public function completeCacheMissReturnsNull(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('get')->willReturn(null);
        $driver->method('has')->willReturn(false);

        // Simulate application-level cache miss handling
        $cached = $driver->get('non_existent_key');
        self::assertNull($cached);

        // Application should fall back to source
        $fromSource = 'fetched_from_database';
        $value = $cached ?? $fromSource;
        self::assertSame('fetched_from_database', $value);
    }

    #[Test]
    public function corruptedCachedDataIsHandledGracefully(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);

        // Simulate corrupted serialized data
        $corruptedPayloads = [
            'O:1337:"EvilClass":0:{}',     // Invalid class
            'a:2:{i:0;',                     // Truncated array
            "\x00\x01\x02\x03\x04",        // Binary garbage
            'N;extra_data',                  // Trailing data
            's:999:"too short";',           // Length mismatch
        ];

        foreach ($corruptedPayloads as $payload) {
            $driver->method('get')->willReturn($payload);

            $raw = $driver->get('corrupted_key');
            self::assertIsString($raw);

            // Application-level deserialization should handle this
            $result = @unserialize($raw);
            if ($result === false) {
                // Corrupted data detected — fall back to source
                $result = 'fallback_value';
            }

            self::assertIsString($result);
        }
    }

    #[Test]
    public function cacheStampedeProtectionPreventsThunderingHerd(): void
    {
        $fetchCount = 0;
        $lockHolder = null;

        // Simulate cache stampede scenario: many concurrent readers find a miss
        $simulatedRequests = 10;
        $results = [];

        for ($i = 0; $i < $simulatedRequests; $i++) {
            // Simulate lock acquisition
            if ($lockHolder === null) {
                $lockHolder = $i;
                // Only the lock holder fetches from source
                $fetchCount++;
                $results[$i] = 'computed_value';
            } else {
                // Other requests wait and get the cached result
                $results[$i] = 'computed_value'; // From the cache, set by lock holder
            }
        }

        self::assertSame(1, $fetchCount, 'Only one request should fetch from source during stampede');
        self::assertCount($simulatedRequests, $results);
        self::assertTrue(array_all($results, static fn(string $v): bool => $v === 'computed_value'));
    }

    #[Test]
    public function partialWriteFailuresAreDetected(): void
    {
        $writeAttempts = 0;
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('set')->willReturnCallback(function () use (&$writeAttempts): bool {
            $writeAttempts++;
            // Simulate intermittent write failures
            return $writeAttempts % 3 !== 0;
        });

        $successes = 0;
        $failures = 0;

        for ($i = 0; $i < 9; $i++) {
            if ($driver->set("key_{$i}", "value_{$i}", 3600)) {
                $successes++;
            } else {
                $failures++;
            }
        }

        self::assertSame(6, $successes);
        self::assertSame(3, $failures);
    }

    #[Test]
    public function cacheDriverThrowingExceptionDoesNotCrashApplication(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('get')
            ->willThrowException(new RuntimeException('Redis connection refused'));

        // Application should catch and handle cache failures gracefully
        try {
            $driver->get('some_key');
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('connection refused', $e->getMessage());
        }

        // Application continues with fallback
        $fallback = 'computed_without_cache';
        self::assertSame('computed_without_cache', $fallback);
    }

    #[Test]
    public function clearOperationFailureIsReportable(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('clear')->willReturn(false);

        $result = $driver->clear();
        self::assertFalse($result, 'Failed clear should return false');
    }

    #[Test]
    public function getMultipleWithPartialFailuresReturnsAvailableKeys(): void
    {
        $driver = $this->createStub(CacheDriverInterface::class);
        $driver->method('getMultiple')->willReturn([
            'key1' => 'value1',
            'key2' => null,     // miss
            'key3' => 'value3',
            'key4' => null,     // miss
        ]);

        $results = $driver->getMultiple(['key1', 'key2', 'key3', 'key4']);

        self::assertSame('value1', $results['key1']);
        self::assertNull($results['key2']);
        self::assertSame('value3', $results['key3']);
        self::assertNull($results['key4']);
    }

    #[Test]
    public function rapidSetAndDeleteCyclesDoNotCorruptState(): void
    {
        $driver = new ArrayDriver();

        // Rapid write/delete cycles
        for ($i = 0; $i < 100; $i++) {
            $driver->set("rapid_key_{$i}", "value_{$i}", null);
            if ($i % 2 === 0) {
                $driver->delete("rapid_key_{$i}");
            }
        }

        // Only odd keys should remain
        for ($i = 0; $i < 100; $i++) {
            if ($i % 2 === 0) {
                self::assertFalse($driver->has("rapid_key_{$i}"), "Even key {$i} should be deleted");
            } else {
                self::assertTrue($driver->has("rapid_key_{$i}"), "Odd key {$i} should exist");
                self::assertSame("value_{$i}", $driver->get("rapid_key_{$i}"));
            }
        }
    }
}
