<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\ArrayDriver;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;

#[CoversClass(ArrayDriver::class)]
final class ArrayDriverTest extends TestCase
{
    use CacheDriverContractTrait;

    protected function setUp(): void
    {
        $this->driver = $this->createDriver();
    }

    protected function createDriver(): CacheDriverInterface
    {
        return new ArrayDriver();
    }

    #[Test]
    public function incrementInitializesToOneByDefault(): void
    {
        $result = $this->driver->increment('counter');

        self::assertSame(1, $result);
        self::assertSame('1', $this->driver->get('counter'));
    }

    #[Test]
    public function incrementAddsToExistingValue(): void
    {
        $this->driver->set('counter', '10', null);

        $result = $this->driver->increment('counter', 5);

        self::assertSame(15, $result);
        self::assertSame('15', $this->driver->get('counter'));
    }

    #[Test]
    public function decrementSubtractsFromExistingValue(): void
    {
        $this->driver->set('counter', '10', null);

        $result = $this->driver->decrement('counter', 3);

        self::assertSame(7, $result);
        self::assertSame('7', $this->driver->get('counter'));
    }

    #[Test]
    public function decrementInitializesToNegativeStep(): void
    {
        $result = $this->driver->decrement('counter', 5);

        self::assertSame(-5, $result);
    }

    #[Test]
    public function capabilitiesAreSupportsBinaryAndAtomicIncrement(): void
    {
        $capabilities = $this->driver->capabilities();

        self::assertFalse($capabilities->supportsTagsStrict);
        self::assertFalse($capabilities->supportsLocksFencing);
        self::assertTrue($capabilities->supportsBinary);
        self::assertTrue($capabilities->supportsAtomicIncrement);
    }

    #[Test]
    public function nameReturnsArray(): void
    {
        self::assertSame('array', $this->driver->name());
    }
}
