<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Supervisor\HealingAction;
use Pulsar\Supervisor\HealingActionType;
use ReflectionClass;

use function time;

#[CoversClass(HealingAction::class)]
final class HealingActionTest extends TestCase
{
    #[Test]
    public function it_stores_all_constructor_values(): void
    {
        $now = time();
        $action = new HealingAction(
            id: 'heal-001',
            type: HealingActionType::StuckJobRecovery,
            description: 'Recovered stuck job xyz',
            performedAt: $now,
            success: true,
            correlationId: 'job-xyz',
        );

        self::assertSame('heal-001', $action->id);
        self::assertSame(HealingActionType::StuckJobRecovery, $action->type);
        self::assertSame('Recovered stuck job xyz', $action->description);
        self::assertSame($now, $action->performedAt);
        self::assertTrue($action->success);
        self::assertSame('job-xyz', $action->correlationId);
    }

    #[Test]
    public function it_allows_null_correlation_id(): void
    {
        $action = new HealingAction(
            id: 'heal-002',
            type: HealingActionType::CachePurge,
            description: 'Purged application caches',
            performedAt: time(),
            success: true,
        );

        self::assertNull($action->correlationId);
    }

    #[Test]
    public function it_records_failure(): void
    {
        $action = new HealingAction(
            id: 'heal-003',
            type: HealingActionType::ConnectionReset,
            description: 'Failed to reset database connection',
            performedAt: time(),
            success: false,
        );

        self::assertFalse($action->success);
        self::assertSame(HealingActionType::ConnectionReset, $action->type);
    }

    #[Test]
    public function it_supports_worker_recycle_type(): void
    {
        $action = new HealingAction(
            id: 'heal-004',
            type: HealingActionType::WorkerRecycle,
            description: 'Worker recycled due to memory threshold',
            performedAt: time(),
            success: true,
            correlationId: 'worker-1',
        );

        self::assertSame(HealingActionType::WorkerRecycle, $action->type);
    }

    #[Test]
    public function it_supports_all_healing_action_types(): void
    {
        $types = HealingActionType::cases();

        self::assertCount(4, $types);
        self::assertSame('worker_recycle', HealingActionType::WorkerRecycle->value);
        self::assertSame('stuck_job_recovery', HealingActionType::StuckJobRecovery->value);
        self::assertSame('cache_purge', HealingActionType::CachePurge->value);
        self::assertSame('connection_reset', HealingActionType::ConnectionReset->value);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $action = new HealingAction(
            id: 'heal-005',
            type: HealingActionType::CachePurge,
            description: 'Cache purged',
            performedAt: time(),
            success: true,
        );

        $reflection = new ReflectionClass($action);
        self::assertTrue($reflection->isReadOnly());
    }
}
