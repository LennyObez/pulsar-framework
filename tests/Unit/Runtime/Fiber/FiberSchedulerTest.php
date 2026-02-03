<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Fiber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Fiber\FiberScheduler;

#[CoversClass(FiberScheduler::class)]
final class FiberSchedulerTest extends TestCase
{
    #[Test]
    public function it_starts_with_zero_active_fibers(): void
    {
        $scheduler = new FiberScheduler(maxConcurrency: 10);

        self::assertSame(0, $scheduler->activeFiberCount());
    }

    #[Test]
    public function it_reports_capacity_when_empty(): void
    {
        $scheduler = new FiberScheduler(maxConcurrency: 10);

        self::assertTrue($scheduler->hasCapacity());
    }

    #[Test]
    public function it_clears_all_fibers(): void
    {
        $scheduler = new FiberScheduler(maxConcurrency: 10);
        $scheduler->clear();

        self::assertSame(0, $scheduler->activeFiberCount());
    }

    #[Test]
    public function tick_returns_zero_with_no_sockets(): void
    {
        $scheduler = new FiberScheduler(maxConcurrency: 10);

        $resumed = $scheduler->tick(0.01);

        self::assertSame(0, $resumed);
    }

    #[Test]
    public function drain_returns_zero_with_no_fibers(): void
    {
        $scheduler = new FiberScheduler(maxConcurrency: 10);

        $remaining = $scheduler->drain(0.1);

        self::assertSame(0, $remaining);
    }

    #[Test]
    public function unlimited_concurrency_always_has_capacity(): void
    {
        $scheduler = new FiberScheduler(maxConcurrency: 0);

        self::assertTrue($scheduler->hasCapacity());
    }
}
