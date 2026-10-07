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
final class WorkflowInstanceTest extends TestCase
{
    #[Test]
    public function test_constructor_stores_all_fields(): void
    {
        $now = new DateTimeImmutable();
        $context = new ClassifiedContext();

        $instance = new WorkflowInstance(
            id: 'inst-1',
            definitionId: 'order_process',
            definitionVersion: 2,
            currentState: 'draft',
            context: $context,
            version: 1,
            status: WorkflowInstanceStatus::Active,
            startedAt: $now,
            completedAt: null,
            startedBy: 'user-42',
        );

        self::assertSame('inst-1', $instance->id);
        self::assertSame('order_process', $instance->definitionId);
        self::assertSame(2, $instance->definitionVersion);
        self::assertSame('draft', $instance->currentState);
        self::assertSame($context, $instance->context);
        self::assertSame(1, $instance->version);
        self::assertSame(WorkflowInstanceStatus::Active, $instance->status);
        self::assertSame($now, $instance->startedAt);
        self::assertNull($instance->completedAt);
        self::assertSame('user-42', $instance->startedBy);
    }

    #[Test]
    public function test_with_state_creates_new_instance_with_updated_state(): void
    {
        $instance = $this->createInstance();
        $updated = $instance->withState('review', 2);

        self::assertNotSame($instance, $updated);
        self::assertSame('review', $updated->currentState);
        self::assertSame(2, $updated->version);
        self::assertSame('draft', $instance->currentState);
        self::assertSame(1, $instance->version);
    }

    #[Test]
    public function test_with_state_preserves_other_fields(): void
    {
        $instance = $this->createInstance();
        $updated = $instance->withState('review', 2);

        self::assertSame($instance->id, $updated->id);
        self::assertSame($instance->definitionId, $updated->definitionId);
        self::assertSame($instance->definitionVersion, $updated->definitionVersion);
        self::assertSame($instance->context, $updated->context);
        self::assertSame($instance->status, $updated->status);
        self::assertSame($instance->startedAt, $updated->startedAt);
        self::assertSame($instance->startedBy, $updated->startedBy);
    }

    #[Test]
    public function test_with_status_creates_new_instance_with_updated_status(): void
    {
        $instance = $this->createInstance();
        $updated = $instance->withStatus(WorkflowInstanceStatus::Failed);

        self::assertNotSame($instance, $updated);
        self::assertSame(WorkflowInstanceStatus::Failed, $updated->status);
        self::assertSame(WorkflowInstanceStatus::Active, $instance->status);
    }

    #[Test]
    public function test_with_status_preserves_other_fields(): void
    {
        $instance = $this->createInstance();
        $updated = $instance->withStatus(WorkflowInstanceStatus::Completed);

        self::assertSame($instance->id, $updated->id);
        self::assertSame($instance->currentState, $updated->currentState);
        self::assertSame($instance->version, $updated->version);
    }

    #[Test]
    public function test_with_completed_sets_status_and_timestamp(): void
    {
        $instance = $this->createInstance();
        $completedAt = new DateTimeImmutable('2026-03-01 12:00:00');
        $updated = $instance->withCompleted($completedAt);

        self::assertNotSame($instance, $updated);
        self::assertSame(WorkflowInstanceStatus::Completed, $updated->status);
        self::assertSame($completedAt, $updated->completedAt);
    }

    #[Test]
    public function test_with_completed_preserves_other_fields(): void
    {
        $instance = $this->createInstance();
        $updated = $instance->withCompleted(new DateTimeImmutable());

        self::assertSame($instance->id, $updated->id);
        self::assertSame($instance->currentState, $updated->currentState);
        self::assertSame($instance->version, $updated->version);
        self::assertSame($instance->startedBy, $updated->startedBy);
    }

    #[Test]
    public function test_workflow_instance_status_values(): void
    {
        self::assertSame('active', WorkflowInstanceStatus::Active->value);
        self::assertSame('completed', WorkflowInstanceStatus::Completed->value);
        self::assertSame('failed', WorkflowInstanceStatus::Failed->value);
        self::assertSame('compensating', WorkflowInstanceStatus::Compensating->value);
    }

    private function createInstance(): WorkflowInstance
    {
        return new WorkflowInstance(
            id: 'inst-1',
            definitionId: 'test',
            definitionVersion: 1,
            currentState: 'draft',
            context: new ClassifiedContext(),
            version: 1,
            status: WorkflowInstanceStatus::Active,
            startedAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            completedAt: null,
            startedBy: 'user-1',
        );
    }
}
