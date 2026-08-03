<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Engine;

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
use Pulsar\Workflow\Storage\WorkflowInstance;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;
use Pulsar\Workflow\Storage\WorkflowStorageInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function is_array;

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

    #[Test]
    public function test_start_creates_instance_in_initial_state(): void
    {
        $storage = $this->createMock(WorkflowStorageInterface::class);
        $storage->expects(self::once())
            ->method('create')
            ->with(self::callback(static function (WorkflowInstance $instance): bool {
                return $instance->currentState === 'draft'
                    && $instance->definitionId === 'order'
                    && $instance->definitionVersion === 1
                    && $instance->status === WorkflowInstanceStatus::Active
                    && $instance->startedBy === 'user-1';
            }));

        $engine = $this->createEngineWith(storage: $storage);
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');

        $instance = $engine->start($definition, $actor, new ClassifiedContext());

        self::assertSame('draft', $instance->currentState);
        self::assertSame(WorkflowInstanceStatus::Active, $instance->status);
        self::assertSame('user-1', $instance->startedBy);
    }

    #[Test]
    public function test_start_dispatches_workflow_started_event(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(WorkflowStartedEvent::class));

        $engine = $this->createEngineWith(eventDispatcher: $dispatcher);
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');

        $engine->start($definition, $actor, new ClassifiedContext());
    }

    #[Test]
    public function test_start_logs_audit_entry(): void
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
                self::callback(static fn(mixed $v): bool => is_array($v)),
            )
            ->willReturn($this->createStub(AuditEntry::class));

        $engine = $this->createEngineWith(auditLogger: $auditLogger);
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');

        $engine->start($definition, $actor, new ClassifiedContext());
    }

    #[Test]
    public function test_apply_transitions_to_new_state(): void
    {
        $storage = $this->createMock(WorkflowStorageInterface::class);
        $transitionLog = $this->createMock(TransitionLogInterface::class);

        $updatedInstance = $this->createInstance('review', 2);

        $storage->expects(self::once())
            ->method('updateState')
            ->with('inst-1', 'review', 1, self::isInstanceOf(ActorContext::class), null)
            ->willReturn($updatedInstance);

        $transitionLog->expects(self::once())->method('record');

        $engine = $this->createEngineWith(storage: $storage, transitionLog: $transitionLog);
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        $result = $engine->apply($instance, $definition, 'submit', $actor);

        self::assertSame('review', $result->currentState);
        self::assertSame(2, $result->version);
    }

    #[Test]
    public function test_apply_dispatches_transition_applied_event(): void
    {
        $this->storage->method('updateState')->willReturn($this->createInstance('review', 2));

        $dispatchedEvents = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $engine = $this->createEngineWith(eventDispatcher: $dispatcher);
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        $engine->apply($instance, $definition, 'submit', $actor);

        $transitionEvents = array_values(array_filter(
            $dispatchedEvents,
            static fn(object $e): bool => $e instanceof TransitionAppliedEvent,
        ));
        self::assertCount(1, $transitionEvents);
        self::assertSame('submit', $transitionEvents[0]->transitionName);
        self::assertSame('draft', $transitionEvents[0]->fromState);
        self::assertSame('review', $transitionEvents[0]->toState);
    }

    #[Test]
    public function test_apply_with_reason_passes_reason_through(): void
    {
        $storage = $this->createMock(WorkflowStorageInterface::class);
        $storage->expects(self::once())
            ->method('updateState')
            ->with('inst-1', 'review', 1, self::isInstanceOf(ActorContext::class), 'Ready for review')
            ->willReturn($this->createInstance('review', 2));

        $engine = $this->createEngineWith(storage: $storage);
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        $engine->apply($instance, $definition, 'submit', $actor, 'Ready for review');
    }

    #[Test]
    public function test_apply_throws_when_transition_not_defined(): void
    {
        $engine = $this->createEngine();
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessageIsOrContains('not valid');

        $engine->apply($instance, $definition, 'nonexistent', $actor);
    }

    #[Test]
    public function test_apply_throws_when_transition_not_valid_from_current_state(): void
    {
        $engine = $this->createEngine();
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        $this->expectException(WorkflowException::class);

        $engine->apply($instance, $definition, 'complete', $actor);
    }

    #[Test]
    public function test_apply_throws_when_in_final_state(): void
    {
        $engine = $this->createEngine();
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('done', 3);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessageIsOrContains('final state');

        $engine->apply($instance, $definition, 'submit', $actor);
    }

    #[Test]
    public function test_apply_evaluates_guards_and_blocks_when_denied(): void
    {
        $guard = $this->createStub(TransitionGuardInterface::class);
        $guard->method('evaluate')->willReturn(GuardResult::deny('Missing role'));

        $this->guardResolver->method('resolve')->willReturn($guard);

        $engine = $this->createEngine();
        $definition = DefinitionBuilder::create('guarded')
            ->initialState('draft')
            ->finalState('approved')
            ->transition('approve', 'draft', 'approved', guards: [RoleGuard::class])
            ->build();

        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessageIsOrContains('blocked by guard');

        $engine->apply($instance, $definition, 'approve', $actor);
    }

    #[Test]
    public function test_apply_dispatches_blocked_event_when_guard_denies(): void
    {
        $guard = $this->createStub(TransitionGuardInterface::class);
        $guard->method('evaluate')->willReturn(GuardResult::deny('Unauthorized'));

        $this->guardResolver->method('resolve')->willReturn($guard);

        $dispatchedEvents = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $engine = $this->createEngineWith(eventDispatcher: $dispatcher);
        $definition = DefinitionBuilder::create('guarded')
            ->initialState('draft')
            ->finalState('approved')
            ->transition('approve', 'draft', 'approved', guards: [RoleGuard::class])
            ->build();

        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        try {
            $engine->apply($instance, $definition, 'approve', $actor);
        } catch (WorkflowException) {
            // Expected
        }

        $blockedEvents = array_filter(
            $dispatchedEvents,
            static fn(object $e): bool => $e instanceof TransitionBlockedEvent,
        );
        self::assertCount(1, $blockedEvents);
    }

    #[Test]
    public function test_apply_allows_when_guard_passes(): void
    {
        $guard = $this->createStub(TransitionGuardInterface::class);
        $guard->method('evaluate')->willReturn(GuardResult::allow());

        $this->guardResolver->method('resolve')->willReturn($guard);
        $this->storage->method('updateState')->willReturn(
            $this->createInstance('approved', 2, WorkflowInstanceStatus::Active),
        );

        $engine = $this->createEngine();
        $definition = DefinitionBuilder::create('guarded')
            ->initialState('draft')
            ->finalState('approved')
            ->transition('approve', 'draft', 'approved', guards: [RoleGuard::class])
            ->build();

        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        $result = $engine->apply($instance, $definition, 'approve', $actor);

        self::assertSame('approved', $result->currentState);
    }

    #[Test]
    public function test_apply_to_final_state_dispatches_completed_event(): void
    {
        $storage = $this->createMock(WorkflowStorageInterface::class);
        $storage->method('updateState')->willReturn($this->createInstance('done', 3));
        $storage->expects(self::once())
            ->method('updateStatus')
            ->with('inst-1', WorkflowInstanceStatus::Completed);

        $dispatchedEvents = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $engine = $this->createEngineWith(storage: $storage, eventDispatcher: $dispatcher);
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('review', 2);

        $engine->apply($instance, $definition, 'complete', $actor);

        $completedEvents = array_values(array_filter(
            $dispatchedEvents,
            static fn(object $e): bool => $e instanceof WorkflowCompletedEvent,
        ));
        self::assertCount(1, $completedEvents);
        self::assertSame('done', $completedEvents[0]->finalState);
    }

    #[Test]
    public function test_apply_notifies_listeners(): void
    {
        $listener = $this->createMock(TransitionListenerInterface::class);

        $listener->expects(self::once())->method('onLeave');
        $listener->expects(self::once())->method('onTransition');
        $listener->expects(self::once())->method('onEnter');

        $this->storage->method('updateState')->willReturn($this->createInstance('review', 2));

        $engine = $this->createEngine([$listener]);
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        $engine->apply($instance, $definition, 'submit', $actor);
    }

    #[Test]
    public function test_can_returns_true_for_valid_transition(): void
    {
        $engine = $this->createEngine();
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        self::assertTrue($engine->can($instance, $definition, 'submit', $actor));
    }

    #[Test]
    public function test_can_returns_false_for_undefined_transition(): void
    {
        $engine = $this->createEngine();
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        self::assertFalse($engine->can($instance, $definition, 'nonexistent', $actor));
    }

    #[Test]
    public function test_can_returns_false_when_transition_not_from_current_state(): void
    {
        $engine = $this->createEngine();
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        self::assertFalse($engine->can($instance, $definition, 'complete', $actor));
    }

    #[Test]
    public function test_can_returns_false_when_in_final_state(): void
    {
        $engine = $this->createEngine();
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('done', 3);

        self::assertFalse($engine->can($instance, $definition, 'submit', $actor));
    }

    #[Test]
    public function test_can_returns_false_when_guard_denies(): void
    {
        $guard = $this->createStub(TransitionGuardInterface::class);
        $guard->method('evaluate')->willReturn(GuardResult::deny('No access'));

        $this->guardResolver->method('resolve')->willReturn($guard);

        $engine = $this->createEngine();
        $definition = DefinitionBuilder::create('guarded')
            ->initialState('draft')
            ->finalState('approved')
            ->transition('approve', 'draft', 'approved', guards: [RoleGuard::class])
            ->build();

        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        self::assertFalse($engine->can($instance, $definition, 'approve', $actor));
    }

    #[Test]
    public function test_get_enabled_transitions_returns_available_transitions(): void
    {
        $engine = $this->createEngine();
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        $enabled = $engine->getEnabledTransitions($instance, $definition, $actor);

        self::assertCount(1, $enabled);
        self::assertSame('submit', $enabled[0]->name);
    }

    #[Test]
    public function test_get_enabled_transitions_filters_out_guard_blocked(): void
    {
        $guard = $this->createStub(TransitionGuardInterface::class);
        $guard->method('evaluate')->willReturn(GuardResult::deny('Blocked'));

        $this->guardResolver->method('resolve')->willReturn($guard);

        $engine = $this->createEngine();
        $definition = DefinitionBuilder::create('guarded')
            ->initialState('draft')
            ->state('review')
            ->finalState('approved')
            ->transition('submit', 'draft', 'review', guards: [RoleGuard::class])
            ->transition('direct_approve', 'draft', 'approved')
            ->transition('approve', 'review', 'approved')
            ->build();

        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('draft', 1);

        $enabled = $engine->getEnabledTransitions($instance, $definition, $actor);

        self::assertCount(1, $enabled);
        self::assertSame('direct_approve', $enabled[0]->name);
    }

    #[Test]
    public function test_get_enabled_transitions_returns_empty_for_final_state(): void
    {
        $engine = $this->createEngine();
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');
        $instance = $this->createInstance('done', 3);

        $enabled = $engine->getEnabledTransitions($instance, $definition, $actor);

        self::assertSame([], $enabled);
    }

    #[Test]
    public function test_full_lifecycle_start_transition_complete(): void
    {
        $storage = $this->createMock(WorkflowStorageInterface::class);

        $storage->expects(self::once())->method('create');
        $storage->method('updateState')
            ->willReturnOnConsecutiveCalls(
                $this->createInstance('review', 2),
                $this->createInstance('done', 3),
            );
        $storage->expects(self::once())
            ->method('updateStatus')
            ->with('inst-1', WorkflowInstanceStatus::Completed);

        $engine = $this->createEngineWith(storage: $storage);
        $definition = $this->buildSimpleDefinition();
        $actor = new ActorContext(subjectId: 'user-1');

        $started = $engine->start($definition, $actor, new ClassifiedContext());
        self::assertSame('draft', $started->currentState);

        $submitted = $engine->apply($started, $definition, 'submit', $actor);
        self::assertSame('review', $submitted->currentState);

        $completed = $engine->apply($submitted, $definition, 'complete', $actor);
        self::assertSame('done', $completed->currentState);
    }

    /**
     * @param list<TransitionListenerInterface> $listeners
     */
    private function createEngine(array $listeners = []): WorkflowEngine
    {
        return new WorkflowEngine(
            storage: $this->storage,
            transitionLog: $this->transitionLog,
            guardResolver: $this->guardResolver,
            eventDispatcher: $this->eventDispatcher,
            auditLogger: $this->auditLogger,
            listeners: $listeners,
            randomizer: new Randomizer(new Mt19937(42)),
        );
    }

    private function createEngineWith(
        ?WorkflowStorageInterface $storage = null,
        ?TransitionLogInterface $transitionLog = null,
        ?GuardResolverInterface $guardResolver = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?AuditLoggerInterface $auditLogger = null,
    ): WorkflowEngine {
        $actualAuditLogger = $auditLogger ?? $this->auditLogger;

        return new WorkflowEngine(
            storage: $storage ?? $this->storage,
            transitionLog: $transitionLog ?? $this->transitionLog,
            guardResolver: $guardResolver ?? $this->guardResolver,
            eventDispatcher: $eventDispatcher ?? $this->eventDispatcher,
            auditLogger: $actualAuditLogger,
            randomizer: new Randomizer(new Mt19937(42)),
        );
    }

    private function buildSimpleDefinition(): WorkflowDefinition
    {
        return DefinitionBuilder::create('order')
            ->initialState('draft')
            ->state('review')
            ->finalState('done')
            ->transition('submit', 'draft', 'review')
            ->transition('complete', 'review', 'done')
            ->build();
    }

    private function createInstance(
        string $state,
        int $version,
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
}
