<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Worker\WorkerHealthStatus;

#[CoversClass(WorkerHealthStatus::class)]
final class WorkerHealthStatusTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        self::assertSame('healthy', WorkerHealthStatus::Healthy->value);
        self::assertSame('draining', WorkerHealthStatus::Draining->value);
        self::assertSame('shutting_down', WorkerHealthStatus::ShuttingDown->value);
    }

    #[Test]
    public function it_creates_from_string_value(): void
    {
        self::assertSame(WorkerHealthStatus::Healthy, WorkerHealthStatus::from('healthy'));
        self::assertSame(WorkerHealthStatus::Draining, WorkerHealthStatus::from('draining'));
        self::assertSame(WorkerHealthStatus::ShuttingDown, WorkerHealthStatus::from('shutting_down'));
    }

    #[Test]
    public function it_has_three_cases(): void
    {
        self::assertCount(3, WorkerHealthStatus::cases());
    }
}
