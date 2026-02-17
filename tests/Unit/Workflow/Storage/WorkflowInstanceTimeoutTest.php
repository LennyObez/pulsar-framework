<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Storage\ClassifiedContext;
use Pulsar\Workflow\Storage\WorkflowInstance;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;

#[CoversClass(WorkflowInstance::class)]
final class WorkflowInstanceTimeoutTest extends TestCase
{
    #[Test]
    public function constructsWithTimeoutAt(): void
    {
        $timeoutAt = new DateTimeImmutable('2026-03-20 12:00:00');

        $instance = $this->createInstance(timeoutAt: $timeoutAt);

        self::assertSame($timeoutAt, $instance->timeoutAt);
    }

    #[Test]
    public function constructsWithNullTimeoutByDefault(): void
    {
        $instance = $this->createInstance();

        self::assertNull($instance->timeoutAt);
    }

    #[Test]
    public function withTimeoutSetsDeadline(): void
    {
        $instance = $this->createInstance();
        $deadline = new DateTimeImmutable('2026-03-20 18:00:00');

        $updated = $instance->withTimeout($deadline);

        self::assertNotSame($instance, $updated);
        self::assertSame($deadline, $updated->timeoutAt);
        self::assertNull($instance->timeoutAt);
    }

    #[Test]
    public function withTimeoutClearsDeadline(): void
    {
        $deadline = new DateTimeImmutable('2026-03-20 18:00:00');
        $instance = $this->createInstance(timeoutAt: $deadline);

        $updated = $instance->withTimeout(null);

        self::assertNull($updated->timeoutAt);
        self::assertSame($deadline, $instance->timeoutAt);
    }

    #[Test]
    public function withTimeoutPreservesOtherFields(): void
    {
        $instance = $this->createInstance();
        $deadline = new DateTimeImmutable('2026-03-20 18:00:00');

        $updated = $instance->withTimeout($deadline);

        self::assertSame($instance->id, $updated->id);
        self::assertSame($instance->definitionId, $updated->definitionId);
        self::assertSame($instance->definitionVersion, $updated->definitionVersion);
        self::assertSame($instance->currentState, $updated->currentState);
        self::assertSame($instance->context, $updated->context);
        self::assertSame($instance->version, $updated->version);
        self::assertSame($instance->status, $updated->status);
        self::assertSame($instance->startedAt, $updated->startedAt);
        self::assertSame($instance->completedAt, $updated->completedAt);
        self::assertSame($instance->startedBy, $updated->startedBy);
    }

    #[Test]
    public function withTimeoutReplacesExistingTimeout(): void
    {
        $first = new DateTimeImmutable('2026-03-20 12:00:00');
        $second = new DateTimeImmutable('2026-03-21 12:00:00');

        $instance = $this->createInstance(timeoutAt: $first);
        $updated = $instance->withTimeout($second);

        self::assertSame($second, $updated->timeoutAt);
        self::assertSame($first, $instance->timeoutAt);
    }

    #[Test]
    public function withStatePreservesTimeout(): void
    {
        $deadline = new DateTimeImmutable('2026-03-20 18:00:00');
        $instance = $this->createInstance(timeoutAt: $deadline);

        $updated = $instance->withState('review', 2);

        self::assertSame($deadline, $updated->timeoutAt);
        self::assertSame('review', $updated->currentState);
    }

    #[Test]
    public function withStatusPreservesTimeout(): void
    {
        $deadline = new DateTimeImmutable('2026-03-20 18:00:00');
        $instance = $this->createInstance(timeoutAt: $deadline);

        $updated = $instance->withStatus(WorkflowInstanceStatus::Compensating);

        self::assertSame($deadline, $updated->timeoutAt);
        self::assertSame(WorkflowInstanceStatus::Compensating, $updated->status);
    }

    #[Test]
    public function withCompletedPreservesTimeout(): void
    {
        $deadline = new DateTimeImmutable('2026-03-20 18:00:00');
        $instance = $this->createInstance(timeoutAt: $deadline);

        $completedAt = new DateTimeImmutable('2026-03-19 10:00:00');
        $updated = $instance->withCompleted($completedAt);

        self::assertSame($deadline, $updated->timeoutAt);
        self::assertSame(WorkflowInstanceStatus::Completed, $updated->status);
    }

    private function createInstance(?DateTimeImmutable $timeoutAt = null): WorkflowInstance
    {
        return new WorkflowInstance(
            id: 'inst-timeout-1',
            definitionId: 'approval_flow',
            definitionVersion: 1,
            currentState: 'pending',
            context: new ClassifiedContext(),
            version: 1,
            status: WorkflowInstanceStatus::Active,
            startedAt: new DateTimeImmutable('2026-03-20 08:00:00'),
            completedAt: null,
            startedBy: 'user-42',
            timeoutAt: $timeoutAt,
        );
    }
}
