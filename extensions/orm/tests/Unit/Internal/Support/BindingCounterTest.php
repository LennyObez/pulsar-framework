<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Internal\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Internal\Support\BindingCounter;

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

        self::assertSame('bind0', $counter->next('bind'));
        self::assertSame('bind1', $counter->next('bind'));
    }

    #[Test]
    public function currentReturnsCounterValue(): void
    {
        $counter = new BindingCounter();

        self::assertSame(0, $counter->current());
        (void) $counter->next();
        self::assertSame(1, $counter->current());
    }

    #[Test]
    public function resetRestartsCounter(): void
    {
        $counter = new BindingCounter();
        (void) $counter->next();
        (void) $counter->next();

        $counter->reset();

        self::assertSame(0, $counter->current());
        self::assertSame('p0', $counter->next());
    }
}
