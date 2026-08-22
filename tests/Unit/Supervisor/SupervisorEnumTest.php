<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Supervisor;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Supervisor\HealingActionType;
use Pulsar\Supervisor\RecycleAction;
use Pulsar\Supervisor\RecycleReason;

#[CoversNothing]
final class SupervisorEnumTest extends TestCase
{
    // ── RecycleReason ───────────────────────────────────────────────────

    #[Test]
    public function recycleReasonHasFourCases(): void
    {
        self::assertCount(4, RecycleReason::cases());
    }

    #[Test]
    #[DataProvider('recycleReasonProvider')]
    public function recycleReasonBackedValues(RecycleReason $reason, string $expected): void
    {
        self::assertSame($expected, $reason->value);
    }

    /**
     * @return iterable<string, array{RecycleReason, string}>
     */
    public static function recycleReasonProvider(): iterable
    {
        yield 'MaxRequests' => [RecycleReason::MaxRequests, 'max_requests'];
        yield 'MemoryThreshold' => [RecycleReason::MemoryThreshold, 'memory_threshold'];
        yield 'TimeLimit' => [RecycleReason::TimeLimit, 'time_limit'];
        yield 'Manual' => [RecycleReason::Manual, 'manual'];
    }

    // ── RecycleAction ───────────────────────────────────────────────────

    #[Test]
    public function recycleActionHasThreeCases(): void
    {
        self::assertCount(3, RecycleAction::cases());
    }

    #[Test]
    #[DataProvider('recycleActionProvider')]
    public function recycleActionBackedValues(RecycleAction $action, string $expected): void
    {
        self::assertSame($expected, $action->value);
    }

    /**
     * @return iterable<string, array{RecycleAction, string}>
     */
    public static function recycleActionProvider(): iterable
    {
        yield 'GracefulRestart' => [RecycleAction::GracefulRestart, 'graceful_restart'];
        yield 'ForceRestart' => [RecycleAction::ForceRestart, 'force_restart'];
        yield 'Skip' => [RecycleAction::Skip, 'skip'];
    }

    // ── HealingActionType ───────────────────────────────────────────────

    #[Test]
    public function healingActionTypeHasFourCases(): void
    {
        self::assertCount(4, HealingActionType::cases());
    }

    #[Test]
    #[DataProvider('healingActionTypeProvider')]
    public function healingActionTypeBackedValues(HealingActionType $type, string $expected): void
    {
        self::assertSame($expected, $type->value);
    }

    /**
     * @return iterable<string, array{HealingActionType, string}>
     */
    public static function healingActionTypeProvider(): iterable
    {
        yield 'WorkerRecycle' => [HealingActionType::WorkerRecycle, 'worker_recycle'];
        yield 'StuckJobRecovery' => [HealingActionType::StuckJobRecovery, 'stuck_job_recovery'];
        yield 'CachePurge' => [HealingActionType::CachePurge, 'cache_purge'];
        yield 'ConnectionReset' => [HealingActionType::ConnectionReset, 'connection_reset'];
    }

    #[Test]
    public function recycleReasonFromBackedValue(): void
    {
        self::assertSame(RecycleReason::Manual, RecycleReason::from('manual'));
    }

    #[Test]
    public function recycleActionFromBackedValue(): void
    {
        self::assertSame(RecycleAction::GracefulRestart, RecycleAction::from('graceful_restart'));
    }

    #[Test]
    public function healingActionTypeFromBackedValue(): void
    {
        self::assertSame(HealingActionType::CachePurge, HealingActionType::from('cache_purge'));
    }
}
