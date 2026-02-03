<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Supervisor\RecycleAction;
use Pulsar\Supervisor\RecycleReason;
use Pulsar\Supervisor\RecycleRecord;
use ReflectionClass;

use function time;

#[CoversClass(RecycleRecord::class)]
final class RecycleRecordTest extends TestCase
{
    #[Test]
    public function it_stores_all_constructor_values(): void
    {
        $now = time();
        $record = new RecycleRecord(
            reason: RecycleReason::MaxRequests,
            action: RecycleAction::GracefulRestart,
            memoryUsageMb: 200,
            requestCount: 15000,
            uptimeSeconds: 3600,
            performedAt: $now,
        );

        self::assertSame(RecycleReason::MaxRequests, $record->reason);
        self::assertSame(RecycleAction::GracefulRestart, $record->action);
        self::assertSame(200, $record->memoryUsageMb);
        self::assertSame(15000, $record->requestCount);
        self::assertSame(3600, $record->uptimeSeconds);
        self::assertSame($now, $record->performedAt);
    }

    #[Test]
    public function it_accepts_memory_threshold_reason(): void
    {
        $record = new RecycleRecord(
            reason: RecycleReason::MemoryThreshold,
            action: RecycleAction::GracefulRestart,
            memoryUsageMb: 512,
            requestCount: 100,
            uptimeSeconds: 60,
            performedAt: time(),
        );

        self::assertSame(RecycleReason::MemoryThreshold, $record->reason);
    }

    #[Test]
    public function it_accepts_time_limit_reason(): void
    {
        $record = new RecycleRecord(
            reason: RecycleReason::TimeLimit,
            action: RecycleAction::GracefulRestart,
            memoryUsageMb: 50,
            requestCount: 10,
            uptimeSeconds: 7200,
            performedAt: time(),
        );

        self::assertSame(RecycleReason::TimeLimit, $record->reason);
    }

    #[Test]
    public function it_accepts_manual_reason(): void
    {
        $record = new RecycleRecord(
            reason: RecycleReason::Manual,
            action: RecycleAction::ForceRestart,
            memoryUsageMb: 100,
            requestCount: 50,
            uptimeSeconds: 300,
            performedAt: time(),
        );

        self::assertSame(RecycleReason::Manual, $record->reason);
        self::assertSame(RecycleAction::ForceRestart, $record->action);
    }

    #[Test]
    public function it_accepts_skip_action(): void
    {
        $record = new RecycleRecord(
            reason: RecycleReason::MaxRequests,
            action: RecycleAction::Skip,
            memoryUsageMb: 10,
            requestCount: 1000,
            uptimeSeconds: 60,
            performedAt: time(),
        );

        self::assertSame(RecycleAction::Skip, $record->action);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $record = new RecycleRecord(
            reason: RecycleReason::MaxRequests,
            action: RecycleAction::GracefulRestart,
            memoryUsageMb: 100,
            requestCount: 500,
            uptimeSeconds: 120,
            performedAt: time(),
        );

        $reflection = new ReflectionClass($record);
        self::assertTrue($reflection->isReadOnly());
    }
}
