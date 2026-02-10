<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Internal\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;

#[CoversClass(BindingCounter::class)]
final class BindingCounterTest extends TestCase
{
    #[Test]
    public function nextGeneratesSequentialNames(): void
    {
        $counter = new BindingCounter();

        self::assertSame('p0', $counter->next());
        self::assertSame('p1', $counter->next());
        self::assertSame('p2', $counter->next());
    }

    #[Test]
    public function nextWithCustomPrefix(): void
    {
        $counter = new BindingCounter();

        self::assertSame('v0', $counter->next('v'));
        self::assertSame('v1', $counter->next('v'));
    }

    #[Test]
    public function resetRestartsCounter(): void
    {
        $counter = new BindingCounter();
        $_ = $counter->next();
        $_ = $counter->next();

        $counter->reset();

        self::assertSame('p0', $counter->next());
    }

    #[Test]
    public function currentReturnsCounterValue(): void
    {
        $counter = new BindingCounter();

        self::assertSame(0, $counter->current());

        $_ = $counter->next();
        self::assertSame(1, $counter->current());

        $_ = $counter->next();
        self::assertSame(2, $counter->current());
    }

    #[Test]
    public function mixedPrefixesShareCounter(): void
    {
        $counter = new BindingCounter();

        self::assertSame('p0', $counter->next());
        self::assertSame('v1', $counter->next('v'));
        self::assertSame('p2', $counter->next());
    }
}
