<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Driver;

use PHPUnit\Framework\Attributes\Test;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;

/**
 * Shared contract tests for all CacheDriverInterface implementations.
 *
 * Consuming test classes must implement {@see createDriver()} and call it
 * in their setUp() to assign {@see $driver}.
 */
trait CacheDriverContractTrait
{
    protected CacheDriverInterface $driver;

    abstract protected function createDriver(): CacheDriverInterface;

    #[Test]
    public function getReturnsNullForNonExistentKey(): void
    {
        self::assertNull($this->driver->get('nonexistent'));
    }

    #[Test]
    public function setAndGetRoundTrip(): void
    {
        self::assertTrue($this->driver->set('key', 'value', 3600));
        self::assertSame('value', $this->driver->get('key'));
    }

    #[Test]
    public function setWithTtlNullNeverExpires(): void
    {
        self::assertTrue($this->driver->set('key', 'value', null));
        self::assertSame('value', $this->driver->get('key'));
    }

    #[Test]
    public function setWithTtlZeroDeletesKey(): void
    {
        $this->driver->set('key', 'value', 3600);
        self::assertTrue($this->driver->set('key', 'updated', 0));
        self::assertNull($this->driver->get('key'));
    }

    #[Test]
    public function setWithNegativeTtlDeletesKey(): void
    {
        $this->driver->set('key', 'value', 3600);
        self::assertTrue($this->driver->set('key', 'updated', -1));
        self::assertNull($this->driver->get('key'));
    }

    #[Test]
    public function addStoresTheValueWhenTheKeyIsAbsent(): void
    {
        self::assertTrue($this->driver->add('add-key', 'first', 3600));
        self::assertSame('first', $this->driver->get('add-key'));
    }

    #[Test]
    public function addDoesNotOverwriteAnExistingKey(): void
    {
        self::assertTrue($this->driver->add('add-key', 'first', 3600));
        self::assertFalse($this->driver->add('add-key', 'second', 3600));
        self::assertSame('first', $this->driver->get('add-key'));
    }

    #[Test]
    public function deleteRemovesExistingKey(): void
    {
        $this->driver->set('key', 'value', 3600);
        self::assertTrue($this->driver->delete('key'));
        self::assertNull($this->driver->get('key'));
    }

    #[Test]
    public function deleteReturnsTrueForNonExistentKey(): void
    {
        self::assertTrue($this->driver->delete('nonexistent'));
    }

    #[Test]
    public function hasReturnsTrueForExistingKey(): void
    {
        $this->driver->set('key', 'value', 3600);
        self::assertTrue($this->driver->has('key'));
    }

    #[Test]
    public function hasReturnsFalseForNonExistentKey(): void
    {
        self::assertFalse($this->driver->has('nonexistent'));
    }

    #[Test]
    public function clearRemovesAllKeys(): void
    {
        $this->driver->set('a', '1', 3600);
        $this->driver->set('b', '2', 3600);
        self::assertTrue($this->driver->clear());
        self::assertNull($this->driver->get('a'));
        self::assertNull($this->driver->get('b'));
    }

    #[Test]
    public function getMultipleReturnsMixOfHitsAndMisses(): void
    {
        $this->driver->set('hit', 'found', 3600);

        $result = $this->driver->getMultiple(['hit', 'miss']);

        self::assertSame('found', $result['hit']);
        self::assertNull($result['miss']);
    }

    #[Test]
    public function setMultipleStoresAllValues(): void
    {
        self::assertTrue($this->driver->setMultiple(['a' => '1', 'b' => '2'], 3600));
        self::assertSame('1', $this->driver->get('a'));
        self::assertSame('2', $this->driver->get('b'));
    }

    #[Test]
    public function deleteMultipleRemovesAllKeys(): void
    {
        $this->driver->set('a', '1', 3600);
        $this->driver->set('b', '2', 3600);
        self::assertTrue($this->driver->deleteMultiple(['a', 'b']));
        self::assertNull($this->driver->get('a'));
        self::assertNull($this->driver->get('b'));
    }
}
