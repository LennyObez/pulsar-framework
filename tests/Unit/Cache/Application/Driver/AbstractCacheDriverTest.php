<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\AbstractCacheDriver;
use Pulsar\Cache\Application\Driver\CacheDriverCapabilities;

#[CoversClass(AbstractCacheDriver::class)]
final class AbstractCacheDriverTest extends TestCase
{
    #[Test]
    public function normalizeTtlReturnsNullForNullInput(): void
    {
        $driver = $this->createTestDriver();

        self::assertNull($driver->callNormalizeTtl(null));
    }

    #[Test]
    #[DataProvider('zerOrNegativeTtlValues')]
    public function normalizeTtlReturnsZeroForZeroOrNegative(int $input): void
    {
        $driver = $this->createTestDriver();

        self::assertSame(0, $driver->callNormalizeTtl($input));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function zerOrNegativeTtlValues(): iterable
    {
        yield 'zero' => [0];
        yield 'negative one' => [-1];
        yield 'large negative' => [-99999];
    }

    #[Test]
    public function normalizeTtlReturnsPositiveValueUnchanged(): void
    {
        $driver = $this->createTestDriver();

        self::assertSame(300, $driver->callNormalizeTtl(300));
        self::assertSame(1, $driver->callNormalizeTtl(1));
        self::assertSame(86400, $driver->callNormalizeTtl(86400));
    }

    #[Test]
    public function isExpiredTtlReturnsFalseForNull(): void
    {
        $driver = $this->createTestDriver();

        self::assertFalse($driver->callIsExpiredTtl(null));
    }

    #[Test]
    public function isExpiredTtlReturnsTrueForZero(): void
    {
        $driver = $this->createTestDriver();

        self::assertTrue($driver->callIsExpiredTtl(0));
    }

    #[Test]
    public function isExpiredTtlReturnsTrueForNegative(): void
    {
        $driver = $this->createTestDriver();

        self::assertTrue($driver->callIsExpiredTtl(-1));
        self::assertTrue($driver->callIsExpiredTtl(-100));
    }

    #[Test]
    public function isExpiredTtlReturnsFalseForPositive(): void
    {
        $driver = $this->createTestDriver();

        self::assertFalse($driver->callIsExpiredTtl(1));
        self::assertFalse($driver->callIsExpiredTtl(3600));
    }

    #[Test]
    public function getMultipleDelegatesToGetForEachKey(): void
    {
        $driver = $this->createTestDriver(['a' => 'val-a', 'c' => 'val-c']);

        $result = $driver->getMultiple(['a', 'b', 'c']);

        self::assertSame('val-a', $result['a']);
        self::assertNull($result['b']);
        self::assertSame('val-c', $result['c']);
    }

    #[Test]
    public function setMultipleDelegatesToSetForEachKeyValue(): void
    {
        $driver = $this->createTestDriver();

        $success = $driver->setMultiple(['x' => 'vx', 'y' => 'vy'], 60);

        self::assertTrue($success);
        self::assertSame('vx', $driver->get('x'));
        self::assertSame('vy', $driver->get('y'));
    }

    #[Test]
    public function setMultipleReturnsFalseWhenAnySetFails(): void
    {
        $driver = $this->createTestDriver(failOnSet: true);

        $success = $driver->setMultiple(['a' => '1'], 60);

        self::assertFalse($success);
    }

    #[Test]
    public function deleteMultipleDelegatesToDeleteForEachKey(): void
    {
        $driver = $this->createTestDriver(['a' => '1', 'b' => '2']);

        $success = $driver->deleteMultiple(['a', 'b']);

        self::assertTrue($success);
        self::assertNull($driver->get('a'));
        self::assertNull($driver->get('b'));
    }

    #[Test]
    public function incrementReturnsFalseByDefault(): void
    {
        $driver = $this->createTestDriver();

        self::assertFalse($driver->increment('key'));
    }

    #[Test]
    public function decrementReturnsFalseByDefault(): void
    {
        $driver = $this->createTestDriver();

        self::assertFalse($driver->decrement('key'));
    }

    /**
     * @param array<string, string> $store
     */
    private function createTestDriver(array $store = [], bool $failOnSet = false): ConcreteTestCacheDriver
    {
        return new ConcreteTestCacheDriver($store, $failOnSet);
    }
}

/**
 * Minimal concrete implementation of AbstractCacheDriver for testing protected methods.
 *
 * @internal Test-only
 */
final class ConcreteTestCacheDriver extends AbstractCacheDriver
{
    /** @var array<string, string> */
    private array $store;
    private bool $failOnSet;

    /**
     * @param array<string, string> $store
     */
    public function __construct(array $store = [], bool $failOnSet = false)
    {
        $this->store = $store;
        $this->failOnSet = $failOnSet;
    }

    public function callNormalizeTtl(?int $ttl): ?int
    {
        return $this->normalizeTtl($ttl);
    }

    public function callIsExpiredTtl(?int $ttl): bool
    {
        return $this->isExpiredTtl($ttl);
    }

    public function get(string $key): ?string
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        if ($this->failOnSet) {
            return false;
        }

        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

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

    public function capabilities(): CacheDriverCapabilities
    {
        return new CacheDriverCapabilities();
    }

    public function name(): string
    {
        return 'test';
    }
}
