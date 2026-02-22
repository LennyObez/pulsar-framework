<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\RuntimeType;
use Pulsar\Runtime\Worker\WorkerInfo;
use Pulsar\Runtime\Worker\WorkerState;
use ReflectionClass;

#[CoversClass(WorkerInfo::class)]
final class WorkerInfoTest extends TestCase
{
    #[Test]
    public function it_constructs_with_all_properties(): void
    {
        $info = new WorkerInfo(
            pid: 12345,
            startedAt: 1700000000,
            requestCount: 42,
            memoryUsageMb: 64,
            state: WorkerState::Ready,
            runtimeType: RuntimeType::Persistent,
        );

        self::assertSame(12345, $info->pid);
        self::assertSame(1700000000, $info->startedAt);
        self::assertSame(42, $info->requestCount);
        self::assertSame(64, $info->memoryUsageMb);
        self::assertSame(WorkerState::Ready, $info->state);
        self::assertSame(RuntimeType::Persistent, $info->runtimeType);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $info = new WorkerInfo(
            pid: 1,
            startedAt: 0,
            requestCount: 0,
            memoryUsageMb: 0,
            state: WorkerState::Stopped,
            runtimeType: RuntimeType::Fpm,
        );

        $reflection = new ReflectionClass($info);
        self::assertTrue($reflection->isReadOnly());
    }
}
