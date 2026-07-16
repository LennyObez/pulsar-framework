<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Prefix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Driver\ArrayDriver;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Exception\UnsupportedCapabilityException;
use Pulsar\Cache\Application\Prefix\PrefixedCacheDecorator;

#[CoversClass(PrefixedCacheDecorator::class)]
final class PrefixedCacheDecoratorTest extends TestCase
{
    private ArrayDriver $inner;
    private PrefixedCacheDecorator $decorator;

    protected function setUp(): void
    {
        $this->inner = new ArrayDriver();
        $this->decorator = new PrefixedCacheDecorator(inner: $this->inner, prefix: 'app_a.cache.');
    }

    #[Test]
    public function keysArePrefixedAtRestAndTransparentToTheCaller(): void
    {
        $this->decorator->set('user.42', 'payload', null);

        self::assertSame('payload', $this->inner->get('app_a.cache.user.42'), 'Storage key must carry the prefix');
        self::assertNull($this->inner->get('user.42'), 'The unprefixed key must not exist');
        self::assertSame('payload', $this->decorator->get('user.42'));
        self::assertTrue($this->decorator->has('user.42'));
    }

    #[Test]
    public function getMultipleMapsStorageKeysBackToLogicalKeys(): void
    {
        $this->decorator->setMultiple(['a' => '1', 'b' => '2'], null);

        $result = $this->decorator->getMultiple(['a', 'b', 'missing']);

        self::assertSame(['a' => '1', 'b' => '2', 'missing' => null], $result);
    }

    #[Test]
    public function clearDeletesExactlyThisPrefixAndNothingElse(): void
    {
        // Two "pools" (prefixes) sharing one backend: clearing pool A must
        // leave pool B and unprefixed keys untouched — the whole point of the
        // prefix versus FLUSHDB.
        $poolB = new PrefixedCacheDecorator(inner: $this->inner, prefix: 'app_b.cache.');
        $this->decorator->set('k', 'from-a', null);
        $poolB->set('k', 'from-b', null);
        $this->inner->set('bare', 'untouched', null);

        self::assertTrue($this->decorator->clear());

        self::assertNull($this->decorator->get('k'), 'Pool A must be empty');
        self::assertSame('from-b', $poolB->get('k'), 'Pool B must survive pool A\'s clear()');
        self::assertSame('untouched', $this->inner->get('bare'));
    }

    #[Test]
    public function clearThrowsInsteadOfFlushingWhenTheDriverCannotEnumerate(): void
    {
        // A driver without key enumeration (e.g. Memcached) cannot scope
        // clear() to the prefix; silently flushing the whole shared server
        // would be worse than failing, so it must throw.
        $blind = $this->createStub(CacheDriverInterface::class);
        $blind->method('name')->willReturn('memcached');

        $decorator = new PrefixedCacheDecorator(inner: $blind, prefix: 'p.');

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage('cannot enumerate keys');

        $decorator->clear();
    }

    #[Test]
    public function addAndCountersOperateOnThePrefixedKey(): void
    {
        self::assertTrue($this->decorator->add('claim', 'first', null));
        self::assertFalse($this->decorator->add('claim', 'second', null));

        self::assertSame(2, $this->decorator->increment('hits', 2));
        self::assertSame(1, $this->decorator->decrement('hits'));
        self::assertSame('1', $this->inner->get('app_a.cache.hits'));
    }

    #[Test]
    public function deleteMultipleAndNameAreMapped(): void
    {
        $this->decorator->setMultiple(['x' => '1', 'y' => '2'], null);
        $this->decorator->deleteMultiple(['x', 'y']);

        self::assertNull($this->decorator->get('x'));
        self::assertNull($this->decorator->get('y'));
        self::assertSame('prefixed:array', $this->decorator->name());
    }
}
