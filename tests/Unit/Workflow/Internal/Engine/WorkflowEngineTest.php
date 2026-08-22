<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Internal\Engine;

use ArrayObject;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Definition\DefinitionBuilder;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Definition\WorkflowDefinition;
use Pulsar\Workflow\Event\TransitionAppliedEvent;
use Pulsar\Workflow\Event\TransitionBlockedEvent;
use Pulsar\Workflow\Event\WorkflowCompletedEvent;
use Pulsar\Workflow\Event\WorkflowStartedEvent;
use Pulsar\Workflow\Exception\WorkflowException;
use Pulsar\Workflow\Guard\GuardResolverInterface;
use Pulsar\Workflow\Guard\GuardResult;
use Pulsar\Workflow\Guard\RoleGuard;
use Pulsar\Workflow\Guard\TransitionGuardInterface;
use Pulsar\Workflow\Internal\Engine\WorkflowEngine;
use Pulsar\Workflow\Listener\TransitionListenerInterface;
use Pulsar\Workflow\Storage\ClassifiedContext;
use Pulsar\Workflow\Storage\TransitionLogInterface;
use Pulsar\Workflow\Storage\TransitionRecord;
use Pulsar\Workflow\Storage\WorkflowInstance;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;
use Pulsar\Workflow\Storage\WorkflowStorageInterface;
use Pulsar\Workflow\Timeout\TimeoutHandlerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use stdClass;

use function array_filter;
use function array_map;
use function array_values;
use function assert;
use function is_array;
use function is_string;
use function strlen;

/**
 * Comprehensive unit tests for WorkflowEngine focusing on:
 * - Workflow start lifecycle (initial state, events, audit)
 * - Transition application (state change, guard evaluation, listeners)
 * - Final state detection and completion events
 * - Guard evaluation and denial handling
 * - Timeout scheduling and cancellation
 * - Enabled transition listing
 * - Edge cases (multiple guards, guard audit logging, reason propagation)
 */
#[CoversClass(WorkflowEngine::class)]
final class WorkflowEngineTest extends TestCase
{
    private WorkflowStorageInterface&Stub $storage;
    private TransitionLogInterface&Stub $transitionLog;
    private GuardResolverInterface&Stub $guardResolver;
    private EventDispatcherInterface&Stub $eventDispatcher;
    private AuditLoggerInterface&Stub $auditLogger;

    protected function setUp(): void
    {
        $this->storage = $this->createStub(WorkflowStorageInterface::class);
        $this->transitionLog = $this->createStub(TransitionLogInterface::class);
        $this->guardResolver = $this->createStub(GuardResolverInterface::class);
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->auditLogger = $this->createStub(AuditLoggerInterface::class);

        $this->auditLogger->method('log')->willReturn($this->createStub(AuditEntry::class));
    }

    /**
     * @param list<TransitionListenerInterface> $listeners
     */
    private function engine(
        ?WorkflowStorageInterface $storage = null,
        ?TransitionLogInterface $transitionLog = null,
        ?GuardResolverInterface $guardResolver = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?AuditLoggerInterface $auditLogger = null,
        array $listeners = [],
        ?TimeoutHandlerInterface $timeoutHandler = null,
    ): WorkflowEngine {
        return new WorkflowEngine(
            storage: $storage ?? $this->storage,
            transitionLog: $transitionLog ?? $this->transitionLog,
            guardResolver: $guardResolver ?? $this->guardResolver,
            eventDispatcher: $eventDispatcher ?? $this->eventDispatcher,
            auditLogger: $auditLogger ?? $this->auditLogger,
            listeners: $listeners,
            timeoutHandler: $timeoutHandler,
            randomizer: new Randomizer(new Mt19937(42)),
        );
    }

    private function simpleDefinition(): WorkflowDefinition
    {
        return DefinitionBuilder::create('order')
            ->initialState('draft')
            ->state('review')
            ->finalState('done')
            ->transition('submit', 'draft', 'review')
            ->transition('complete', 'review', 'done')
            ->build();
    }

    private function instance(
        string $state = 'draft',
        int $version = 1,
        WorkflowInstanceStatus $status = WorkflowInstanceStatus::Active,
    ): WorkflowInstance {
        return new WorkflowInstance(
            id: 'inst-1',
            definitionId: 'order',
            definitionVersion: 1,
            currentState: $state,
            context: new ClassifiedContext(),
            version: $version,
            status: $status,
            startedAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            completedAt: null,
            startedBy: 'user-1',
        );
    }

    /**
     * @return list<object>
     */
    private function &captureEvents(): array
    {
        $events = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$events): object {
                $events[] = $event;

                return $event;
            });
        $this->eventDispatcher = $dispatcher;

        return $events;
    }

    // =========================================================================
    // start()
    // =========================================================================

    #[Test]
    public function start_creates_instance_in_initial_state(): void
    {
        $storage = $this->createMock(WorkflowStorageInterface::class);
        $storage->expects(self::once())
            ->method('create')
            ->with(self::callback(static fn(WorkflowInstance $i): bool => $i->currentState === 'draft'
                && $i->definitionId === 'order'
                && $i->definitionVersion === 1
                && $i->status === WorkflowInstanceStatus::Active
                && $i->startedBy === 'user-1'
                && $i->version === 1));

        $instance = $this->engine(storage: $storage)->start(
            $this->simpleDefinition(),
            new ActorContext(subjectId: 'user-1'),
            new ClassifiedContext(),
        );

        self::assertSame('draft', $instance->currentState);
        self::assertSame(WorkflowInstanceStatus::Active, $instance->status);
        self::assertSame('user-1', $instance->startedBy);
        self::assertSame(1, $instance->version);
        self::assertNotEmpty($instance->id);
    }

    #[Test]
    public function start_dispatches_workflow_started_event(): void
    {
        $events = &$this->captureEvents();
        $this->engine()->start($this->simpleDefinition(), new ActorContext(subjectId: 'user-1'), new ClassifiedContext());

        $started = array_values(array_filter($events, static fn(object $e): bool => $e instanceof WorkflowStartedEvent));
        self::assertCount(1, $started);
        self::assertSame('draft', $started[0]->initialState);
        self::assertSame('order', $started[0]->definitionId);
        self::assertSame(1, $started[0]->definitionVersion);
        self::assertSame('user-1', $started[0]->actor->subjectId);
    }

    #[Test]
    public function start_logs_audit_event(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                'user-1',
                'workflow.start',
                'order',
                self::callback(static fn(mixed $v): bool => is_array($v) && isset($v['initial_state'])),
            )
            ->willReturn($this->createStub(AuditEntry::class));

        $this->engine(auditLogger: $auditLogger)->start(
            $this->simpleDefinition(),
            new ActorContext(subjectId: 'user-1'),
            new ClassifiedContext(),
        );
    }

    #[Test]
    public function start_generates_unique_instance_id(): void
    {
        $instance = $this->engine()->start($this->simpleDefinition(), new ActorContext(subjectId: 'u'), new ClassifiedContext());

        self::assertNotEmpty($instance->id);
        self::assertSame(32, strlen($instance->id)); // 16 bytes -> 32 hex chars
    }

    #[Test]
    public function start_preserves_classified_context(): void
    {
        $ctx = new ClassifiedContext()
            ->set('key', 'value', \Pulsar\Workflow\Storage\ClassificationLevel::Public);

        $instance = $this->engine()->start($this->simpleDefinition(), new ActorContext(subjectId: 'u'), $ctx);

        self::assertTrue($instance->context->has('key'));
        self::assertSame('value', $instance->context->get('key'));
    }

    #[Test]
    public function start_schedules_timeout_when_initial_state_has_timeout_metadata(): void
    {
        $definition = DefinitionBuilder::create('timed')
            ->initialState('waiting', metadata: ['timeout' => 'PT1H'])
            ->finalState('done')
            ->transition('finish', 'waiting', 'done')
            ->build();

        $timeoutHandler = $this->createMock(TimeoutHandlerInterface::class);
        $timeoutHandler->expects(self::once())
            ->method('scheduleTimeout')
            ->with(self::callback(static fn(mixed $v): bool => is_string($v) && $v !== ''), self::isInstanceOf(DateInterval::class));

        $this->engine(timeoutHandler: $timeoutHandler)->start(
            $definition,
            new ActorContext(subjectId: 'u'),
            new ClassifiedContext(),
        );
    }

    #[Test]
    public function start_does_not_schedule_timeout_without_timeout_metadata(): void
    {
        $timeoutHandler = $this->createMock(TimeoutHandlerInterface::class);
        $timeoutHandler->expects(self::never())->method('scheduleTimeout');

        $this->engine(timeoutHandler: $timeoutHandler)->start(
            $this->simpleDefinition(),
            new ActorContext(subjectId: 'u'),
            new ClassifiedContext(),
        );
    }

    #[Test]
    public function start_does_not_schedule_timeout_without_timeout_handler(): void
    {
        // No timeout handler provided — should not crash
        $definition = DefinitionBuilder::create('timed')
            ->initialState('waiting', metadata: ['timeout' => 'PT1H'])
            ->finalState('done')
            ->transition('finish', 'waiting', 'done')
            ->build();

        $instance = $this->engine(timeoutHandler: null)->start(
            $definition,
            new ActorContext(subjectId: 'u'),
            new ClassifiedContext(),
        );

        self::assertSame('waiting', $instance->currentState);
    }

    // =========================================================================
    // apply() — happy path
    // =========================================================================

    #[Test]
    public function apply_transitions_to_new_state(): void
    {
        $storage = $this->createMock(WorkflowStorageInterface::class);
        $storage->expects(self::once())
            ->method('updateState')
            ->with('inst-1', 'review', 1, self::isInstanceOf(ActorContext::class), null)
            ->willReturn($this->instance('review', 2));

        $result = $this->engine(storage: $storage)->apply(
            $this->instance('draft', 1),
            $this->simpleDefinition(),
            'submit',
            new ActorContext(subjectId: 'user-1'),
        );

        self::assertSame('review', $result->currentState);
        self::assertSame(2, $result->version);
    }

    #[Test]
    public function apply_records_transition_in_log(): void
    {
        $this->storage->method('updateState')->willReturn($this->instance('review', 2));

        $transitionLog = $this->createMock(TransitionLogInterface::class);
        $transitionLog->expects(self::once())
            ->method('record')
            ->with(self::callback(static fn(TransitionRecord $r): bool => $r->fromState === 'draft'
                && $r->toState === 'review'
                && $r->transitionName === 'submit'
                && $r->actor === 'user-1'
                && $r->instanceVersion === 2));

        $this->engine(transitionLog: $transitionLog)->apply(
            $this->instance('draft', 1),
            $this->simpleDefinition(),
            'submit',
            new ActorContext(subjectId: 'user-1'),
        );
    }

    #[Test]
    public function apply_dispatches_transition_applied_event(): void
    {
        $this->storage->method('updateState')->willReturn($this->instance('review', 2));

        $events = &$this->captureEvents();
        $this->engine()->apply($this->instance('draft', 1), $this->simpleDefinition(), 'submit', new ActorContext(subjectId: 'user-1'));

        $applied = array_values(array_filter($events, static fn(object $e): bool => $e instanceof TransitionAppliedEvent));
        self::assertCount(1, $applied);
        self::assertSame('submit', $applied[0]->transitionName);
        self::assertSame('draft', $applied[0]->fromState);
        self::assertSame('review', $applied[0]->toState);
        self::assertSame(2, $applied[0]->instanceVersion);
        self::assertSame('user-1', $applied[0]->actor->subjectId);
    }

    #[Test]
    public function apply_with_reason_passes_reason_to_storage_and_log(): void
    {
        $storage = $this->createMock(WorkflowStorageInterface::class);
        $storage->expects(self::once())
            ->method('updateState')
            ->with('inst-1', 'review', 1, self::isInstanceOf(ActorContext::class), 'Ready for review')
            ->willReturn($this->instance('review', 2));

        $this->engine(storage: $storage)->apply(
            $this->instance('draft', 1),
            $this->simpleDefinition(),
            'submit',
            new ActorContext(subjectId: 'user-1'),
            'Ready for review',
        );
    }

    #[Test]
    public function apply_logs_audit_event_with_transition_details(): void
    {
        $this->storage->method('updateState')->willReturn($this->instance('review', 2));

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::atLeastOnce())
            ->method('log')
            ->willReturnCallback(function (AuditEvent $event, AuditOutcome $outcome, ?string $actor, string $action) {
                if ($action === 'workflow.transition') {
                    self::assertSame(AuditEvent::DataModification, $event);
                    self::assertSame(AuditOutcome::Success, $outcome);
                    self::assertSame('user-1', $actor);
                }

                return $this->createStub(AuditEntry::class);
            });

        $this->engine(auditLogger: $auditLogger)->apply(
            $this->instance('draft', 1),
            $this->simpleDefinition(),
            'submit',
            new ActorContext(subjectId: 'user-1'),
        );
    }

    #[Test]
    public function apply_cancels_timeout_and_schedules_new_one_for_intermediate_state(): void
    {
        $definition = DefinitionBuilder::create('timed')
            ->initialState('waiting', metadata: ['timeout' => 'PT1H'])
            ->state('processing', metadata: ['timeout' => 'PT30M'])
            ->finalState('done')
            ->transition('start', 'waiting', 'processing')
            ->transition('finish', 'processing', 'done')
            ->build();

        $this->storage->method('updateState')->willReturn($this->instance('processing', 2));

        $timeoutHandler = $this->createMock(TimeoutHandlerInterface::class);
        $timeoutHandler->expects(self::once())->method('cancelTimeout')->with('inst-1');
        $timeoutHandler->expects(self::once())->method('scheduleTimeout');

        $this->engine(timeoutHandler: $timeoutHandler)->apply(
            $this->instance('waiting', 1),
            $definition,
            'start',
            new ActorContext(subjectId: 'u'),
        );
    }

    #[Test]
    public function apply_cancels_timeout_without_scheduling_for_state_without_timeout(): void
    {
        $definition = DefinitionBuilder::create('timed')
            ->initialState('waiting', metadata: ['timeout' => 'PT1H'])
            ->state('processing') // no timeout
            ->finalState('done')
            ->transition('start', 'waiting', 'processing')
            ->transition('finish', 'processing', 'done')
            ->build();

        $this->storage->method('updateState')->willReturn($this->instance('processing', 2));

        $timeoutHandler = $this->createMock(TimeoutHandlerInterface::class);
        $timeoutHandler->expects(self::once())->method('cancelTimeout');
        $timeoutHandler->expects(self::never())->method('scheduleTimeout');

        $this->engine(timeoutHandler: $timeoutHandler)->apply(
            $this->instance('waiting', 1),
            $definition,
            'start',
            new ActorContext(subjectId: 'u'),
        );
    }

    // =========================================================================
    // apply() — final state
    // =========================================================================

    #[Test]
    public function apply_to_final_state_marks_instance_completed_and_dispatches_event(): void
    {
        $storage = $this->createMock(WorkflowStorageInterface::class);
        $storage->method('updateState')->willReturn($this->instance('done', 3));
        $storage->expects(self::once())
            ->method('updateStatus')
            ->with('inst-1', WorkflowInstanceStatus::Completed);

        $events = &$this->captureEvents();
        $this->engine(storage: $storage)->apply(
            $this->instance('review', 2),
            $this->simpleDefinition(),
            'complete',
            new ActorContext(subjectId: 'user-1'),
        );

        $completed = array_values(array_filter($events, static fn(object $e): bool => $e instanceof WorkflowCompletedEvent));
        self::assertCount(1, $completed);
        self::assertSame('done', $completed[0]->finalState);
        self::assertSame('order', $completed[0]->definitionId);
    }

    #[Test]
    public function apply_to_non_final_state_does_not_dispatch_completed_event(): void
    {
        $this->storage->method('updateState')->willReturn($this->instance('review', 2));

        $events = &$this->captureEvents();
        $this->engine()->apply(
            $this->instance('draft', 1),
            $this->simpleDefinition(),
            'submit',
            new ActorContext(subjectId: 'u'),
        );

        $completed = array_filter($events, static fn(object $e): bool => $e instanceof WorkflowCompletedEvent);
        self::assertCount(0, $completed);
    }

    // =========================================================================
    // apply() — error paths
    // =========================================================================

    #[Test]
    public function apply_throws_when_transition_undefined(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessageIsOrContains('not valid');

        $this->engine()->apply(
            $this->instance('draft', 1),
            $this->simpleDefinition(),
            'nonexistent',
            new ActorContext(subjectId: 'u'),
        );
    }

    #[Test]
    public function apply_throws_when_transition_not_valid_from_current_state(): void
    {
        $this->expectException(WorkflowException::class);

        $this->engine()->apply(
            $this->instance('draft', 1),
            $this->simpleDefinition(),
            'complete', // valid from 'review', not 'draft'
            new ActorContext(subjectId: 'u'),
        );
    }

    #[Test]
    public function apply_throws_when_in_final_state(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessageIsOrContains('final state');

        $this->engine()->apply(
            $this->instance('done', 3),
            $this->simpleDefinition(),
            'submit',
            new ActorContext(subjectId: 'u'),
        );
    }

    // =========================================================================
    // apply() — guard evaluation
    // =========================================================================

    #[Test]
    public function apply_evaluates_guards_and_blocks_on_denial(): void
    {
        $guard = $this->createStub(TransitionGuardInterface::class);
        $guard->method('evaluate')->willReturn(GuardResult::deny('Missing role'));
        $this->guardResolver->method('resolve')->willReturn($guard);

        $definition = DefinitionBuilder::create('g')
            ->initialState('draft')
            ->finalState('approved')
            ->transition('approve', 'draft', 'approved', guards: [RoleGuard::class])
            ->build();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessageIsOrContains('blocked by guard');

        $this->engine()->apply($this->instance('draft', 1), $definition, 'approve', new ActorContext(subjectId: 'u'));
    }

    #[Test]
    public function apply_dispatches_blocked_event_on_guard_denial(): void
    {
        $guard = $this->createStub(TransitionGuardInterface::class);
        $guard->method('evaluate')->willReturn(GuardResult::deny('Unauthorized'));
        $this->guardResolver->method('resolve')->willReturn($guard);

        $events = &$this->captureEvents();

        $definition = DefinitionBuilder::create('g')
            ->initialState('draft')
            ->finalState('approved')
            ->transition('approve', 'draft', 'approved', guards: [RoleGuard::class])
            ->build();

        try {
            $this->engine()->apply($this->instance('draft', 1), $definition, 'approve', new ActorContext(subjectId: 'u'));
        } catch (WorkflowException) {
            // Expected
        }

        $blocked = array_filter($events, static fn(object $e): bool => $e instanceof TransitionBlockedEvent);
        self::assertCount(1, $blocked);
    }

    #[Test]
    public function apply_passes_when_guard_allows(): void
    {
        $guard = $this->createStub(TransitionGuardInterface::class);
        $guard->method('evaluate')->willReturn(GuardResult::allow());
        $this->guardResolver->method('resolve')->willReturn($guard);
        $this->storage->method('updateState')->willReturn($this->instance('approved', 2));

        $definition = DefinitionBuilder::create('g')
            ->initialState('draft')
            ->finalState('approved')
            ->transition('approve', 'draft', 'approved', guards: [RoleGuard::class])
            ->build();

        $result = $this->engine()->apply($this->instance('draft', 1), $definition, 'approve', new ActorContext(subjectId: 'u'));

        self::assertSame('approved', $result->currentState);
    }

    #[Test]
    public function apply_multiple_guards_all_must_pass(): void
    {
        $allowGuard = $this->createStub(TransitionGuardInterface::class);
        $allowGuard->method('evaluate')->willReturn(GuardResult::allow());

        $denyGuard = $this->createStub(TransitionGuardInterface::class);
        $denyGuard->method('evaluate')->willReturn(GuardResult::deny('Second guard blocks'));

        $callCount = 0;
        $guardResolver = $this->createStub(GuardResolverInterface::class);
        $guardResolver->method('resolve')->willReturnCallback(
            static function () use ($allowGuard, $denyGuard, &$callCount): TransitionGuardInterface {
                $callCount++;

                return $callCount === 1 ? $allowGuard : $denyGuard;
            },
        );

        $definition = DefinitionBuilder::create('g')
            ->initialState('draft')
            ->finalState('approved')
            ->transition('approve', 'draft', 'approved', guards: [stdClass::class, ArrayObject::class])
            ->build();

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessageIsOrContains('blocked by guard');

        $this->engine(guardResolver: $guardResolver)->apply(
            $this->instance('draft', 1),
            $definition,
            'approve',
            new ActorContext(subjectId: 'u'),
        );
    }

    #[Test]
    public function apply_guard_evaluation_is_audit_logged(): void
    {
        $guard = $this->createStub(TransitionGuardInterface::class);
        $guard->method('evaluate')->willReturn(GuardResult::deny('No access'));
        $this->guardResolver->method('resolve')->willReturn($guard);

        $auditCalls = [];
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::atLeastOnce())
            ->method('log')
            ->willReturnCallback(function (AuditEvent $event, AuditOutcome $outcome, ?string $actor, string $action) use (&$auditCalls) {
                $auditCalls[] = ['action' => $action, 'outcome' => $outcome];

                return $this->createStub(AuditEntry::class);
            });

        $definition = DefinitionBuilder::create('g')
            ->initialState('draft')
            ->finalState('approved')
            ->transition('approve', 'draft', 'approved', guards: [RoleGuard::class])
            ->build();

        try {
            $this->engine(auditLogger: $auditLogger)->apply(
                $this->instance('draft', 1),
                $definition,
                'approve',
                new ActorContext(subjectId: 'u'),
            );
        } catch (WorkflowException) {
            // Expected
        }

        $guardAudit = array_filter($auditCalls, static fn(array $c): bool => $c['action'] === 'workflow.guard.evaluate');
        self::assertNotEmpty($guardAudit);

        $first = array_values($guardAudit)[0];
        self::assertSame(AuditOutcome::Failure, $first['outcome']);
    }

    // =========================================================================
    // apply() — listener notification
    // =========================================================================

    #[Test]
    public function apply_notifies_all_listeners_in_order(): void
    {
        $this->storage->method('updateState')->willReturn($this->instance('review', 2));

        $callOrder = [];
        $listener = $this->createMock(TransitionListenerInterface::class);
        $listener->expects(self::once())->method('onLeave')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'leave';
            });
        $listener->expects(self::once())->method('onTransition')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'transition';
            });
        $listener->expects(self::once())->method('onEnter')
            ->willReturnCallback(static function () use (&$callOrder): void {
                $callOrder[] = 'enter';
            });

        $this->engine(listeners: [$listener])->apply(
            $this->instance('draft', 1),
            $this->simpleDefinition(),
            'submit',
            new ActorContext(subjectId: 'u'),
        );

        self::assertSame(['leave', 'transition', 'enter'], $callOrder);
    }

    #[Test]
    public function apply_multiple_listeners_all_notified(): void
    {
        $this->storage->method('updateState')->willReturn($this->instance('review', 2));

        $l1 = $this->createMock(TransitionListenerInterface::class);
        $l1->expects(self::once())->method('onLeave');
        $l1->expects(self::once())->method('onTransition');
        $l1->expects(self::once())->method('onEnter');

        $l2 = $this->createMock(TransitionListenerInterface::class);
        $l2->expects(self::once())->method('onLeave');
        $l2->expects(self::once())->method('onTransition');
        $l2->expects(self::once())->method('onEnter');

        $this->engine(listeners: [$l1, $l2])->apply(
            $this->instance('draft', 1),
            $this->simpleDefinition(),
            'submit',
            new ActorContext(subjectId: 'u'),
        );
    }

    #[Test]
    public function apply_onEnter_receives_updated_instance(): void
    {
        $updatedInstance = $this->instance('review', 2);
        $this->storage->method('updateState')->willReturn($updatedInstance);

        $receivedInstance = null;
        $listener = $this->createMock(TransitionListenerInterface::class);
        $listener->method('onLeave');
        $listener->method('onTransition');
        $listener->expects(self::once())
            ->method('onEnter')
            ->willReturnCallback(static function (WorkflowInstance $i) use (&$receivedInstance): void {
                $receivedInstance = $i;
            });

        $this->engine(listeners: [$listener])->apply(
            $this->instance('draft', 1),
            $this->simpleDefinition(),
            'submit',
            new ActorContext(subjectId: 'u'),
        );

        assert($receivedInstance instanceof WorkflowInstance);
        self::assertSame('review', $receivedInstance->currentState);
        self::assertSame(2, $receivedInstance->version);
    }

    // =========================================================================
    // can()
    // =========================================================================

    #[Test]
    public function can_returns_true_for_valid_transition(): void
    {
        self::assertTrue(
            $this->engine()->can($this->instance('draft', 1), $this->simpleDefinition(), 'submit', new ActorContext(subjectId: 'u')),
        );
    }

    #[Test]
    public function can_returns_false_for_undefined_transition(): void
    {
        self::assertFalse(
            $this->engine()->can($this->instance('draft', 1), $this->simpleDefinition(), 'nonexistent', new ActorContext(subjectId: 'u')),
        );
    }

    #[Test]
    public function can_returns_false_when_not_from_current_state(): void
    {
        self::assertFalse(
            $this->engine()->can($this->instance('draft', 1), $this->simpleDefinition(), 'complete', new ActorContext(subjectId: 'u')),
        );
    }

    #[Test]
    public function can_returns_false_when_in_final_state(): void
    {
        self::assertFalse(
            $this->engine()->can($this->instance('done', 3), $this->simpleDefinition(), 'submit', new ActorContext(subjectId: 'u')),
        );
    }

    #[Test]
    public function can_returns_false_when_guard_denies(): void
    {
        $guard = $this->createStub(TransitionGuardInterface::class);
        $guard->method('evaluate')->willReturn(GuardResult::deny('No'));
        $this->guardResolver->method('resolve')->willReturn($guard);

        $definition = DefinitionBuilder::create('g')
            ->initialState('draft')
            ->finalState('approved')
            ->transition('approve', 'draft', 'approved', guards: [RoleGuard::class])
            ->build();

        self::assertFalse(
            $this->engine()->can($this->instance('draft', 1), $definition, 'approve', new ActorContext(subjectId: 'u')),
        );
    }

    #[Test]
    public function can_returns_true_when_all_guards_pass(): void
    {
        $guard = $this->createStub(TransitionGuardInterface::class);
        $guard->method('evaluate')->willReturn(GuardResult::allow());
        $this->guardResolver->method('resolve')->willReturn($guard);

        $definition = DefinitionBuilder::create('g')
            ->initialState('draft')
            ->finalState('approved')
            ->transition('approve', 'draft', 'approved', guards: [RoleGuard::class])
            ->build();

        self::assertTrue(
            $this->engine()->can($this->instance('draft', 1), $definition, 'approve', new ActorContext(subjectId: 'u')),
        );
    }

    // =========================================================================
    // getEnabledTransitions()
    // =========================================================================

    #[Test]
    public function getEnabledTransitions_returns_available_transitions(): void
    {
        $enabled = $this->engine()->getEnabledTransitions(
            $this->instance('draft', 1),
            $this->simpleDefinition(),
            new ActorContext(subjectId: 'u'),
        );

        self::assertCount(1, $enabled);
        self::assertSame('submit', $enabled[0]->name);
    }

    #[Test]
    public function getEnabledTransitions_filters_out_guard_blocked(): void
    {
        $guard = $this->createStub(TransitionGuardInterface::class);
        $guard->method('evaluate')->willReturn(GuardResult::deny('Blocked'));
        $this->guardResolver->method('resolve')->willReturn($guard);

        $definition = DefinitionBuilder::create('g')
            ->initialState('draft')
            ->state('review')
            ->finalState('approved')
            ->transition('guarded_submit', 'draft', 'review', guards: [RoleGuard::class])
            ->transition('direct_approve', 'draft', 'approved')
            ->transition('approve', 'review', 'approved')
            ->build();

        $enabled = $this->engine()->getEnabledTransitions(
            $this->instance('draft', 1),
            $definition,
            new ActorContext(subjectId: 'u'),
        );

        self::assertCount(1, $enabled);
        self::assertSame('direct_approve', $enabled[0]->name);
    }

    #[Test]
    public function getEnabledTransitions_returns_empty_for_final_state(): void
    {
        $enabled = $this->engine()->getEnabledTransitions(
            $this->instance('done', 3),
            $this->simpleDefinition(),
            new ActorContext(subjectId: 'u'),
        );

        self::assertSame([], $enabled);
    }

    #[Test]
    public function getEnabledTransitions_returns_multiple_when_available(): void
    {
        $definition = DefinitionBuilder::create('multi')
            ->initialState('draft')
            ->state('review')
            ->finalState('approved')
            ->finalState('rejected')
            ->transition('submit', 'draft', 'review')
            ->transition('fast_approve', 'draft', 'approved')
            ->transition('reject', 'draft', 'rejected')
            ->transition('approve', 'review', 'approved')
            ->build();

        $enabled = $this->engine()->getEnabledTransitions(
            $this->instance('draft', 1),
            $definition,
            new ActorContext(subjectId: 'u'),
        );

        $names = array_map(static fn(TransitionDefinition $t): string => $t->name, $enabled);
        self::assertContains('submit', $names);
        self::assertContains('fast_approve', $names);
        self::assertContains('reject', $names);
        self::assertCount(3, $enabled);
    }

    // =========================================================================
    // Full lifecycle
    // =========================================================================

    #[Test]
    public function full_lifecycle_start_transition_complete(): void
    {
        $storage = $this->createMock(WorkflowStorageInterface::class);
        $storage->expects(self::once())->method('create');
        $storage->method('updateState')->willReturnOnConsecutiveCalls(
            $this->instance('review', 2),
            $this->instance('done', 3),
        );
        $storage->expects(self::once())
            ->method('updateStatus')
            ->with('inst-1', WorkflowInstanceStatus::Completed);

        $engine = $this->engine(storage: $storage);
        $def = $this->simpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');

        $started = $engine->start($def, $actor, new ClassifiedContext());
        self::assertSame('draft', $started->currentState);

        $submitted = $engine->apply($started, $def, 'submit', $actor);
        self::assertSame('review', $submitted->currentState);

        $completed = $engine->apply($submitted, $def, 'complete', $actor);
        self::assertSame('done', $completed->currentState);
    }

    #[Test]
    public function apply_with_tenant_context(): void
    {
        $this->storage->method('updateState')->willReturn($this->instance('review', 2));

        $actor = new ActorContext(subjectId: 'user-1', tenantId: 'tenant-A');

        $result = $this->engine()->apply(
            $this->instance('draft', 1),
            $this->simpleDefinition(),
            'submit',
            $actor,
        );

        self::assertSame('review', $result->currentState);
    }

    #[Test]
    public function start_does_not_schedule_timeout_for_empty_timeout_string(): void
    {
        $definition = DefinitionBuilder::create('timed')
            ->initialState('waiting', metadata: ['timeout' => ''])
            ->finalState('done')
            ->transition('finish', 'waiting', 'done')
            ->build();

        $timeoutHandler = $this->createMock(TimeoutHandlerInterface::class);
        $timeoutHandler->expects(self::never())->method('scheduleTimeout');

        $this->engine(timeoutHandler: $timeoutHandler)->start(
            $definition,
            new ActorContext(subjectId: 'u'),
            new ClassifiedContext(),
        );
    }

    #[Test]
    public function start_does_not_schedule_timeout_for_non_string_timeout_value(): void
    {
        $definition = DefinitionBuilder::create('timed')
            ->initialState('waiting', metadata: ['timeout' => 42])
            ->finalState('done')
            ->transition('finish', 'waiting', 'done')
            ->build();

        $timeoutHandler = $this->createMock(TimeoutHandlerInterface::class);
        $timeoutHandler->expects(self::never())->method('scheduleTimeout');

        $this->engine(timeoutHandler: $timeoutHandler)->start(
            $definition,
            new ActorContext(subjectId: 'u'),
            new ClassifiedContext(),
        );
    }
}
