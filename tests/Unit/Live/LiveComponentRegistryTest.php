<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\LiveComponent;
use Pulsar\Live\LiveComponentRegistry;

#[CoversClass(LiveComponentRegistry::class)]
final class LiveComponentRegistryTest extends TestCase
{
    #[Test]
    public function registerAndResolve(): void
    {
        $registry = new LiveComponentRegistry();
        $registry->register('counter', CounterStub::class);

        self::assertSame(CounterStub::class, $registry->resolve('counter'));
    }

    #[Test]
    public function resolveReturnsNullForUnknown(): void
    {
        $registry = new LiveComponentRegistry();

        self::assertNull($registry->resolve('nonexistent'));
    }

    #[Test]
    public function hasReturnsTrueForRegistered(): void
    {
        $registry = new LiveComponentRegistry();
        $registry->register('counter', CounterStub::class);

        self::assertTrue($registry->has('counter'));
        self::assertFalse($registry->has('other'));
    }

    #[Test]
    public function namesReturnsAllRegistered(): void
    {
        $registry = new LiveComponentRegistry();
        $registry->register('counter', CounterStub::class);
        $registry->register('todo', CounterStub::class);

        $names = $registry->names();

        self::assertContains('counter', $names);
        self::assertContains('todo', $names);
    }

    #[Test]
    public function countReturnsRegistrationCount(): void
    {
        $registry = new LiveComponentRegistry();

        self::assertSame(0, $registry->count());

        $registry->register('a', CounterStub::class);
        $registry->register('b', CounterStub::class);

        self::assertSame(2, $registry->count());
    }
}

/** @internal */
final class CounterStub extends LiveComponent
{
    public function render(): string
    {
        return '<div>stub</div>';
    }
}
