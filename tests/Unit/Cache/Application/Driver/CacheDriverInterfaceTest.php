<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\CacheDriverCapabilities;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;

#[CoversClass(CacheDriverInterface::class)]
final class CacheDriverInterfaceTest extends TestCase
{
    private function createInMemoryDriver(): CacheDriverInterface
    {
        return new class implements CacheDriverInterface {
            /** @var array<string, string> */
            private array $store = [];

            public function get(string $key): ?string
            {
                return $this->store[$key] ?? null;
            }

            public function getMultiple(array $keys): array
            {
                $result = [];
                foreach ($keys as $key) {
                    $result[$key] = $this->store[$key] ?? null;
                }

                return $result;
            }

            public function set(string $key, string $value, ?int $ttlSeconds): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function add(string $key, string $value, ?int $ttlSeconds): bool
            {
                if (isset($this->store[$key])) {
                    return false;
                }

                $this->store[$key] = $value;

                return true;
            }

            public function setMultiple(array $values, ?int $ttlSeconds): bool
            {
                foreach ($values as $key => $value) {
                    $this->store[$key] = $value;
                }

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function deleteMultiple(array $keys): bool
            {
                foreach ($keys as $key) {
                    unset($this->store[$key]);
                }

                return true;
            }

            public function has(string $key): bool
            {
                return isset($this->store[$key]);
            }

            public function clear(): bool
            {
                $this->store = [];

                return true;
            }

            public function increment(string $key, int $step = 1): int|false
            {
                if ($step < 0) {
                    return false;
                }

                $current = isset($this->store[$key]) ? (int) $this->store[$key] : 0;
                $new = $current + $step;
                $this->store[$key] = (string) $new;

                return $new;
            }

            public function decrement(string $key, int $step = 1): int|false
            {
                if ($step < 0) {
                    return false;
                }

                $current = isset($this->store[$key]) ? (int) $this->store[$key] : 0;
                $new = $current - $step;
                $this->store[$key] = (string) $new;

                return $new;
            }

            public function capabilities(): CacheDriverCapabilities
            {
                return new CacheDriverCapabilities(
                    supportsAtomicIncrement: true,
                    supportsLocksFencing: false,
                );
            }

            public function name(): string
            {
                return 'in-memory-test';
            }
        };
    }

    #[Test]
    public function getReturnsNullForMissingKey(): void
    {
        $driver = $this->createInMemoryDriver();

        self::assertNull($driver->get('missing'));
    }

    #[Test]
    public function setAndGetRoundTrip(): void
    {
        $driver = $this->createInMemoryDriver();

        $driver->set('key', 'value', null);

        self::assertSame('value', $driver->get('key'));
    }

    #[Test]
    public function getMultipleReturnsMixedResults(): void
    {
        $driver = $this->createInMemoryDriver();
        $driver->set('a', '1', null);

        $result = $driver->getMultiple(['a', 'b']);

        self::assertSame('1', $result['a']);
        self::assertNull($result['b']);
    }

    #[Test]
    public function setMultipleStoresAllValues(): void
    {
        $driver = $this->createInMemoryDriver();

        $driver->setMultiple(['x' => 'xx', 'y' => 'yy'], null);

        self::assertSame('xx', $driver->get('x'));
        self::assertSame('yy', $driver->get('y'));
    }

    #[Test]
    public function deleteRemovesKey(): void
    {
        $driver = $this->createInMemoryDriver();
        $driver->set('key', 'val', null);
        $driver->delete('key');

        self::assertNull($driver->get('key'));
        self::assertFalse($driver->has('key'));
    }

    #[Test]
    public function deleteMultipleRemovesAllKeys(): void
    {
        $driver = $this->createInMemoryDriver();
        $driver->setMultiple(['a' => '1', 'b' => '2', 'c' => '3'], null);

        $driver->deleteMultiple(['a', 'c']);

        self::assertNull($driver->get('a'));
        self::assertSame('2', $driver->get('b'));
        self::assertNull($driver->get('c'));
    }

    #[Test]
    public function hasReturnsFalseForMissingKey(): void
    {
        $driver = $this->createInMemoryDriver();

        self::assertFalse($driver->has('missing'));
    }

    #[Test]
    public function hasReturnsTrueForExistingKey(): void
    {
        $driver = $this->createInMemoryDriver();
        $driver->set('exists', 'v', null);

        self::assertTrue($driver->has('exists'));
    }

    #[Test]
    public function clearRemovesAllEntries(): void
    {
        $driver = $this->createInMemoryDriver();
        $driver->setMultiple(['a' => '1', 'b' => '2'], null);

        $driver->clear();

        self::assertFalse($driver->has('a'));
        self::assertFalse($driver->has('b'));
    }

    #[Test]
    public function incrementAndDecrementWork(): void
    {
        $driver = $this->createInMemoryDriver();

        self::assertSame(1, $driver->increment('counter'));
        self::assertSame(4, $driver->increment('counter', 3));
        self::assertSame(2, $driver->decrement('counter', 2));
    }

    #[Test]
    public function capabilitiesReturnsCacheDriverCapabilities(): void
    {
        $driver = $this->createInMemoryDriver();
        $caps = $driver->capabilities();

        self::assertInstanceOf(CacheDriverCapabilities::class, $caps);
    }

    #[Test]
    public function nameReturnsNonEmptyString(): void
    {
        $driver = $this->createInMemoryDriver();

        self::assertSame('in-memory-test', $driver->name());
    }
}
