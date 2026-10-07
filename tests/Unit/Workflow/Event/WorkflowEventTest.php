<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Event\TransitionAppliedEvent;
use Pulsar\Workflow\Event\TransitionBlockedEvent;
use Pulsar\Workflow\Event\WorkflowCompletedEvent;
use Pulsar\Workflow\Event\WorkflowStartedEvent;

#[CoversClass(WorkflowStartedEvent::class)]
#[CoversClass(TransitionAppliedEvent::class)]
#[CoversClass(TransitionBlockedEvent::class)]
#[CoversClass(WorkflowCompletedEvent::class)]
final class WorkflowEventTest extends TestCase
{
    #[Test]
    public function test_workflow_started_event_stores_all_fields(): void
    {
        $actor = new ActorContext(subjectId: 'user-1');
        $occurredAt = new DateTimeImmutable('2026-01-15 10:00:00');

        $event = new WorkflowStartedEvent(
            instanceId: 'inst-1',
            definitionId: 'order_process',
            definitionVersion: 1,
            initialState: 'draft',
            actor: $actor,
            occurredAt: $occurredAt,
        );

        self::assertSame('inst-1', $event->instanceId);
        self::assertSame('order_process', $event->definitionId);
        self::assertSame(1, $event->definitionVersion);
        self::assertSame('draft', $event->initialState);
        self::assertSame($actor, $event->actor);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function test_transition_applied_event_stores_all_fields(): void
    {
        $actor = new ActorContext(subjectId: 'user-2');
        $occurredAt = new DateTimeImmutable('2026-01-15 11:00:00');

        $event = new TransitionAppliedEvent(
            instanceId: 'inst-1',
            definitionId: 'order_process',
            transitionName: 'submit',
            fromState: 'draft',
            toState: 'review',
            actor: $actor,
            reason: 'Ready for approval',
            instanceVersion: 2,
            occurredAt: $occurredAt,
        );

        self::assertSame('inst-1', $event->instanceId);
        self::assertSame('order_process', $event->definitionId);
        self::assertSame('submit', $event->transitionName);
        self::assertSame('draft', $event->fromState);
        self::assertSame('review', $event->toState);
        self::assertSame($actor, $event->actor);
        self::assertSame('Ready for approval', $event->reason);
        self::assertSame(2, $event->instanceVersion);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function test_transition_applied_event_reason_can_be_null(): void
    {
        $event = new TransitionAppliedEvent(
            instanceId: 'inst-1',
            definitionId: 'test',
            transitionName: 'go',
            fromState: 'a',
            toState: 'b',
            actor: new ActorContext(subjectId: 'user-1'),
            reason: null,
            instanceVersion: 1,
            occurredAt: new DateTimeImmutable(),
        );

        self::assertNull($event->reason);
    }

    #[Test]
    public function test_transition_blocked_event_stores_all_fields(): void
    {
        $actor = new ActorContext(subjectId: 'user-3');
        $occurredAt = new DateTimeImmutable('2026-01-15 12:00:00');
        $reasons = ['Missing admin role', 'Context field missing'];

        $event = new TransitionBlockedEvent(
            instanceId: 'inst-1',
            definitionId: 'order_process',
            transitionName: 'approve',
            fromState: 'review',
            actor: $actor,
            guardReasons: $reasons,
            occurredAt: $occurredAt,
        );

        self::assertSame('inst-1', $event->instanceId);
        self::assertSame('order_process', $event->definitionId);
        self::assertSame('approve', $event->transitionName);
        self::assertSame('review', $event->fromState);
        self::assertSame($actor, $event->actor);
        self::assertSame($reasons, $event->guardReasons);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function test_workflow_completed_event_stores_all_fields(): void
    {
        $actor = new ActorContext(subjectId: 'user-4');
        $occurredAt = new DateTimeImmutable('2026-01-15 13:00:00');

        $event = new WorkflowCompletedEvent(
            instanceId: 'inst-1',
            definitionId: 'order_process',
            finalState: 'approved',
            actor: $actor,
            occurredAt: $occurredAt,
        );

        self::assertSame('inst-1', $event->instanceId);
        self::assertSame('order_process', $event->definitionId);
        self::assertSame('approved', $event->finalState);
        self::assertSame($actor, $event->actor);
        self::assertSame($occurredAt, $event->occurredAt);
    }
}
