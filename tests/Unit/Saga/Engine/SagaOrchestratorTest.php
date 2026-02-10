<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Engine;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Saga\Event\IrreversibleSagaFailureEvent;
use Pulsar\Saga\Event\SagaCompensationCompletedEvent;
use Pulsar\Saga\Event\SagaCompensationStartedEvent;
use Pulsar\Saga\Event\SagaCompletedEvent;
use Pulsar\Saga\Event\SagaFailedEvent;
use Pulsar\Saga\Event\SagaStartedEvent;
use Pulsar\Saga\Event\SagaStepCompletedEvent;
use Pulsar\Saga\Event\SagaStepFailedEvent;
use Pulsar\Saga\Exception\CompensationFailedException;
use Pulsar\Saga\Exception\SagaException;
use Pulsar\Saga\Internal\SagaOrchestrator;
use Pulsar\Saga\Port\CommandBusPort;
use Pulsar\Saga\SagaDefinitionBuilder;
use Pulsar\Saga\SagaState;
use Pulsar\Saga\SagaStateStorageInterface;
use Pulsar\Saga\SagaStatus;
use Pulsar\Workflow\Storage\SagaStepDirection;
use Pulsar\Workflow\Storage\SagaStepResult as SagaStepResultRecord;
use Pulsar\Workflow\Storage\SagaStepResultStorageInterface;
use Pulsar\Workflow\Storage\SagaStepStatus;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use stdClass;

use function array_values;
use function count;

#[CoversClass(SagaOrchestrator::class)]
final class SagaOrchestratorTest extends TestCase
{
    private CommandBusPort&Stub $commandBus;
    private SagaStateStorageInterface&Stub $storage;
    private EventDispatcherInterface&Stub $eventDispatcher;

    protected function setUp(): void
    {
        $this->commandBus = $this->createStub(CommandBusPort::class);
        $this->storage = $this->createStub(SagaStateStorageInterface::class);
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
    }

    private function createOrchestrator(): SagaOrchestrator
    {
        return new SagaOrchestrator(
            commandBus: $this->commandBus,
            storage: $this->storage,
            eventDispatcher: $this->eventDispatcher,
            randomizer: new Randomizer(new Mt19937(42)),
        );
    }

    private function createOrchestratorWith(
        ?CommandBusPort $commandBus = null,
        ?SagaStateStorageInterface $storage = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?SagaStepResultStorageInterface $stepResultStorage = null,
    ): SagaOrchestrator {
        return new SagaOrchestrator(
            commandBus: $commandBus ?? $this->commandBus,
            storage: $storage ?? $this->storage,
            eventDispatcher: $eventDispatcher ?? $this->eventDispatcher,
            stepResultStorage: $stepResultStorage,
            randomizer: new Randomizer(new Mt19937(42)),
        );
    }

    // --- Happy path ---

    #[Test]
    public function test_execute_happy_path_all_steps_complete(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->step('reserve')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->step('confirm')
                ->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $orchestrator = $this->createOrchestrator();
        $state = $orchestrator->execute($definition, ['orderId' => 'ORD-1']);

        self::assertSame(SagaStatus::Completed, $state->status);
        self::assertSame(3, $state->currentStepIndex);
        self::assertCount(3, $state->stepResults);
        self::assertNotNull($state->completedAt);
    }

    #[Test]
    public function test_execute_happy_path_context_enriched_by_step_output(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')
                ->forward(stdClass::class)
            ->step('confirm')
                ->forward(stdClass::class)
            ->build();

        $callCount = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    return ['transactionId' => 'txn_123'];
                }

                return [];
            },
        );

        $orchestrator = $this->createOrchestrator();
        $state = $orchestrator->execute($definition, ['orderId' => 'ORD-1']);

        self::assertSame(SagaStatus::Completed, $state->status);
        self::assertArrayHasKey('transactionId', $state->context);
        self::assertSame('txn_123', $state->context['transactionId']);
    }

    #[Test]
    public function test_execute_dispatches_started_and_completed_events(): void
    {
        $definition = SagaDefinitionBuilder::create('simple')
            ->step('step1')
                ->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $dispatched = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $orchestrator = $this->createOrchestratorWith(eventDispatcher: $dispatcher);
        $orchestrator->execute($definition, []);

        $types = array_values(array_filter(
            $dispatched,
            static fn(object $e): bool => $e instanceof SagaStartedEvent,
        ));
        self::assertCount(1, $types);

        $completedTypes = array_values(array_filter(
            $dispatched,
            static fn(object $e): bool => $e instanceof SagaCompletedEvent,
        ));
        self::assertCount(1, $completedTypes);
    }

    #[Test]
    public function test_execute_dispatches_step_completed_events(): void
    {
        $definition = SagaDefinitionBuilder::create('multi')
            ->step('a')
                ->forward(stdClass::class)
            ->step('b')
                ->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $dispatched = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $orchestrator = $this->createOrchestratorWith(eventDispatcher: $dispatcher);
        $orchestrator->execute($definition, []);

        $stepEvents = array_values(array_filter(
            $dispatched,
            static fn(object $e): bool => $e instanceof SagaStepCompletedEvent,
        ));
        self::assertCount(2, $stepEvents);
        self::assertSame('a', $stepEvents[0]->stepName);
        self::assertSame('b', $stepEvents[1]->stepName);
    }

    #[Test]
    public function test_execute_persists_state_after_each_step(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')
                ->forward(stdClass::class)
            ->step('confirm')
                ->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $savedStates = [];
        $storage = $this->createMock(SagaStateStorageInterface::class);
        $storage->expects(self::atLeastOnce())
            ->method('save')
            ->willReturnCallback(static function (SagaState $state) use (&$savedStates): void {
                $savedStates[] = $state;
            });

        $orchestrator = $this->createOrchestratorWith(storage: $storage);
        $orchestrator->execute($definition, []);

        // Initial save + after step 1 + after step 2 + completed save = 4
        self::assertGreaterThanOrEqual(4, count($savedStates));
    }

    // --- Failure with compensation ---

    #[Test]
    public function test_execute_failure_triggers_compensation_in_reverse_order(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->step('reserve')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->step('ship')
                ->forward(stdClass::class)
            ->build();

        $dispatchCalls = [];
        $callCount = 0;
        $commandBus = $this->createMock(CommandBusPort::class);
        $commandBus->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (string $actionClass, array $context, ?string $key) use (&$callCount, &$dispatchCalls): array {
                $callCount++;
                $dispatchCalls[] = ['action' => $actionClass, 'key' => $key, 'call' => $callCount];

                if ($callCount === 3) {
                    throw new RuntimeException('Shipping service down');
                }

                return [];
            });

        $orchestrator = $this->createOrchestratorWith(commandBus: $commandBus);
        $state = $orchestrator->execute($definition, ['orderId' => 'ORD-1']);

        self::assertSame(SagaStatus::Failed, $state->status);
        // Forward: charge(1), reserve(2), ship(3 fails)
        // Compensation: reserve(4), charge(5) — reverse order
        self::assertSame(5, $callCount);
    }

    #[Test]
    public function test_execute_failure_dispatches_step_failed_event(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->step('fail_step')
                ->forward(stdClass::class)
            ->build();

        $callCount = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 2) {
                    throw new RuntimeException('Step failed');
                }

                return [];
            },
        );

        $dispatched = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $orchestrator = $this->createOrchestratorWith(eventDispatcher: $dispatcher);
        $orchestrator->execute($definition, []);

        $failEvents = array_values(array_filter(
            $dispatched,
            static fn(object $e): bool => $e instanceof SagaStepFailedEvent,
        ));
        self::assertCount(1, $failEvents);
        self::assertSame('fail_step', $failEvents[0]->stepName);
        self::assertStringContainsString('Step failed', $failEvents[0]->errorMessage);
    }

    #[Test]
    public function test_execute_failure_dispatches_compensation_events(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->step('fail_step')
                ->forward(stdClass::class)
            ->build();

        $callCount = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 2) {
                    throw new RuntimeException('Step failed');
                }

                return [];
            },
        );

        $dispatched = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $orchestrator = $this->createOrchestratorWith(eventDispatcher: $dispatcher);
        $orchestrator->execute($definition, []);

        $compStarted = array_values(array_filter(
            $dispatched,
            static fn(object $e): bool => $e instanceof SagaCompensationStartedEvent,
        ));
        self::assertCount(1, $compStarted);

        $compCompleted = array_values(array_filter(
            $dispatched,
            static fn(object $e): bool => $e instanceof SagaCompensationCompletedEvent,
        ));
        self::assertCount(1, $compCompleted);

        $failedEvents = array_values(array_filter(
            $dispatched,
            static fn(object $e): bool => $e instanceof SagaFailedEvent,
        ));
        self::assertCount(1, $failedEvents);
    }

    // --- Irreversible step handling ---

    #[Test]
    public function test_execute_irreversible_step_skipped_during_compensation(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->step('send_email')
                ->forward(stdClass::class)
                ->irreversible()
            ->step('finalize')
                ->forward(stdClass::class)
            ->build();

        $callCount = 0;
        $compensationActions = [];
        $commandBus = $this->createMock(CommandBusPort::class);
        $commandBus->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (string $action, array $ctx, ?string $key) use (&$callCount, &$compensationActions): array {
                $callCount++;

                // Forward: charge(1), send_email(2), finalize(3 fails)
                if ($callCount === 3) {
                    throw new RuntimeException('Finalization error');
                }

                // Track compensation calls (4+)
                if ($callCount > 3) {
                    $compensationActions[] = $action;
                }

                return [];
            });

        $dispatched = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $orchestrator = $this->createOrchestratorWith(commandBus: $commandBus, eventDispatcher: $dispatcher);
        $state = $orchestrator->execute($definition, []);

        self::assertSame(SagaStatus::Failed, $state->status);

        // Only charge should be compensated (send_email is irreversible, finalize has no compensation)
        // Forward: 3 calls. Compensation: 1 call (charge only). Total: 4
        self::assertSame(4, $callCount);

        // IrreversibleSagaFailureEvent should be dispatched
        $irreversibleEvents = array_values(array_filter(
            $dispatched,
            static fn(object $e): bool => $e instanceof IrreversibleSagaFailureEvent,
        ));
        self::assertCount(1, $irreversibleEvents);
        self::assertContains('send_email', $irreversibleEvents[0]->completedIrreversibleSteps);
        self::assertNotEmpty($irreversibleEvents[0]->operatorActionHint);
    }

    // --- Idempotency keys ---

    #[Test]
    public function test_execute_passes_idempotency_keys_from_context(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
                ->forwardIdempotencyKey(static function (array $ctx): string {
                    /** @var string $orderId */
                    $orderId = $ctx['orderId'];

                    return 'charge-' . $orderId;
                })
            ->build();

        $receivedKey = null;
        $commandBus = $this->createMock(CommandBusPort::class);
        $commandBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (string $action, array $ctx, ?string $key) use (&$receivedKey): array {
                $receivedKey = $key;

                return [];
            });

        $orchestrator = $this->createOrchestratorWith(commandBus: $commandBus);
        $orchestrator->execute($definition, ['orderId' => 'ORD-42']);

        self::assertSame('charge-ORD-42', $receivedKey);
    }

    #[Test]
    public function test_execute_passes_null_idempotency_key_when_not_configured(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('simple')
                ->forward(stdClass::class)
            ->build();

        $receivedKey = 'sentinel';
        $commandBus = $this->createMock(CommandBusPort::class);
        $commandBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (string $action, array $ctx, ?string $key) use (&$receivedKey): array {
                $receivedKey = $key;

                return [];
            });

        $orchestrator = $this->createOrchestratorWith(commandBus: $commandBus);
        $orchestrator->execute($definition, []);

        self::assertNull($receivedKey);
    }

    // --- Resume ---

    #[Test]
    public function test_resume_continues_from_last_persisted_state(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')
                ->forward(stdClass::class)
            ->step('confirm')
                ->forward(stdClass::class)
            ->build();

        // Simulate a saga that completed step 0 but was interrupted before step 1
        $interruptedState = new SagaState(
            sagaId: 'saga-interrupted',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 1,
            stepResults: [\Pulsar\Saga\Step\StepResult::success('charge')],
            status: SagaStatus::Running,
            context: ['orderId' => 'ORD-1'],
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );

        $this->storage->method('findById')->willReturn($interruptedState);
        $this->commandBus->method('dispatch')->willReturn([]);

        $orchestrator = $this->createOrchestrator();
        $state = $orchestrator->resume('saga-interrupted', $definition);

        self::assertSame(SagaStatus::Completed, $state->status);
        self::assertSame(2, $state->currentStepIndex);
        self::assertCount(2, $state->stepResults);
    }

    #[Test]
    public function test_resume_completed_saga_throws(): void
    {
        $completedState = new SagaState(
            sagaId: 'saga-done',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 2,
            stepResults: [],
            status: SagaStatus::Completed,
            context: [],
            startedAt: new DateTimeImmutable(),
            completedAt: new DateTimeImmutable(),
        );

        $this->storage->method('findById')->willReturn($completedState);

        $orchestrator = $this->createOrchestrator();

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('running');

        $orchestrator->resume('saga-done', SagaDefinitionBuilder::create('test')
            ->step('s')->forward(stdClass::class)->build());
    }

    #[Test]
    public function test_resume_failed_saga_throws(): void
    {
        $failedState = new SagaState(
            sagaId: 'saga-failed',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 1,
            stepResults: [],
            status: SagaStatus::Failed,
            context: [],
            startedAt: new DateTimeImmutable(),
            completedAt: new DateTimeImmutable(),
        );

        $this->storage->method('findById')->willReturn($failedState);

        $orchestrator = $this->createOrchestrator();

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('running');

        $orchestrator->resume('saga-failed', SagaDefinitionBuilder::create('test')
            ->step('s')->forward(stdClass::class)->build());
    }

    #[Test]
    public function test_resume_compensating_saga_continues_compensation(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->step('reserve')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->build();

        $compensatingState = new SagaState(
            sagaId: 'saga-comp',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 2,
            stepResults: [
                \Pulsar\Saga\Step\StepResult::success('charge'),
                \Pulsar\Saga\Step\StepResult::success('reserve'),
            ],
            status: SagaStatus::Compensating,
            context: [],
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );

        $this->storage->method('findById')->willReturn($compensatingState);
        $this->commandBus->method('dispatch')->willReturn([]);

        $orchestrator = $this->createOrchestrator();
        $state = $orchestrator->resume('saga-comp', $definition);

        self::assertSame(SagaStatus::Failed, $state->status);
    }

    #[Test]
    public function test_resume_not_found_throws(): void
    {
        $this->storage->method('findById')->willReturn(null);

        $orchestrator = $this->createOrchestrator();

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('not found');

        $orchestrator->resume('nonexistent', SagaDefinitionBuilder::create('test')
            ->step('s')->forward(stdClass::class)->build());
    }

    // --- Force compensate ---

    #[Test]
    public function test_compensate_running_saga(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->build();

        $runningState = new SagaState(
            sagaId: 'saga-running',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 1,
            stepResults: [\Pulsar\Saga\Step\StepResult::success('charge')],
            status: SagaStatus::Running,
            context: [],
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );

        $this->storage->method('findById')->willReturn($runningState);
        $this->commandBus->method('dispatch')->willReturn([]);

        $orchestrator = $this->createOrchestrator();
        $state = $orchestrator->compensate('saga-running', $definition);

        self::assertSame(SagaStatus::Failed, $state->status);
    }

    #[Test]
    public function test_compensate_non_running_saga_throws(): void
    {
        $completedState = new SagaState(
            sagaId: 'saga-done',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 1,
            stepResults: [],
            status: SagaStatus::Completed,
            context: [],
            startedAt: new DateTimeImmutable(),
            completedAt: new DateTimeImmutable(),
        );

        $this->storage->method('findById')->willReturn($completedState);

        $orchestrator = $this->createOrchestrator();

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('running');

        $orchestrator->compensate('saga-done', SagaDefinitionBuilder::create('test')
            ->step('s')->forward(stdClass::class)->build());
    }

    // --- Compensation failure ---

    #[Test]
    public function test_execute_compensation_failure_throws_CompensationFailedException(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->step('reserve')
                ->forward(stdClass::class)
            ->build();

        $callCount = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$callCount): array {
                $callCount++;

                // Forward step 2 fails
                if ($callCount === 2) {
                    throw new RuntimeException('Reserve failed');
                }

                // Compensation of step 1 also fails
                if ($callCount === 3) {
                    throw new RuntimeException('Refund service down');
                }

                return [];
            },
        );

        $orchestrator = $this->createOrchestrator();

        $this->expectException(CompensationFailedException::class);
        $this->expectExceptionMessage('charge');

        $orchestrator->execute($definition, []);
    }

    // --- Retry policy ---

    #[Test]
    public function test_execute_retries_step_per_retry_policy(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('flaky')
                ->forward(stdClass::class)
                ->retryPolicy(maxAttempts: 3, initialDelayMs: 0)
            ->build();

        $callCount = 0;
        $commandBus = $this->createMock(CommandBusPort::class);
        $commandBus->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(static function () use (&$callCount): array {
                $callCount++;
                if ($callCount < 3) {
                    throw new RuntimeException("Attempt $callCount failed");
                }

                return ['success' => true];
            });

        $orchestrator = $this->createOrchestratorWith(commandBus: $commandBus);
        $state = $orchestrator->execute($definition, []);

        self::assertSame(SagaStatus::Completed, $state->status);
    }

    #[Test]
    public function test_execute_exhausts_retries_then_fails(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('always_fail')
                ->forward(stdClass::class)
                ->retryPolicy(maxAttempts: 2, initialDelayMs: 0)
            ->build();

        $this->commandBus->method('dispatch')->willThrowException(new RuntimeException('Always fails'));

        $orchestrator = $this->createOrchestrator();
        $state = $orchestrator->execute($definition, []);

        self::assertSame(SagaStatus::Failed, $state->status);
    }

    // --- Single step saga ---

    #[Test]
    public function test_execute_single_step_saga(): void
    {
        $definition = SagaDefinitionBuilder::create('simple')
            ->step('only_step')
                ->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn(['result' => 'ok']);

        $orchestrator = $this->createOrchestrator();
        $state = $orchestrator->execute($definition, []);

        self::assertSame(SagaStatus::Completed, $state->status);
        self::assertSame(1, $state->currentStepIndex);
        self::assertCount(1, $state->stepResults);
    }

    // --- Step without compensation ---

    #[Test]
    public function test_execute_step_without_compensation_skipped_during_compensation(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('log_audit')
                ->forward(stdClass::class)
            ->step('fail_step')
                ->forward(stdClass::class)
            ->build();

        $callCount = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 2) {
                    throw new RuntimeException('Failed');
                }

                return [];
            },
        );

        $orchestrator = $this->createOrchestrator();
        $state = $orchestrator->execute($definition, []);

        self::assertSame(SagaStatus::Failed, $state->status);
        // Forward: log_audit(1), fail_step(2 fails). No compensation calls since log_audit has none.
        self::assertSame(2, $callCount);
    }

    // --- Step result storage integration ---

    #[Test]
    public function test_execute_records_step_results_when_storage_provided(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')
                ->forward(stdClass::class)
            ->step('confirm')
                ->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $recorded = [];
        $stepStorage = $this->createMock(SagaStepResultStorageInterface::class);
        $stepStorage->expects(self::atLeastOnce())
            ->method('record')
            ->willReturnCallback(static function (SagaStepResultRecord $record) use (&$recorded): void {
                $recorded[] = $record;
            });
        $stepStorage->expects(self::atLeastOnce())
            ->method('markCompleted');

        $orchestrator = $this->createOrchestratorWith(stepResultStorage: $stepStorage);
        $state = $orchestrator->execute($definition, []);

        self::assertSame(SagaStatus::Completed, $state->status);
        // Two forward steps should each record a "started" entry
        self::assertCount(2, $recorded);
        self::assertSame('charge', $recorded[0]->stepName);
        self::assertSame(SagaStepDirection::Forward, $recorded[0]->direction);
        self::assertSame(SagaStepStatus::Running, $recorded[0]->status);
        self::assertSame('confirm', $recorded[1]->stepName);
    }

    #[Test]
    public function test_execute_records_failed_step_on_failure(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
            ->step('fail_step')
                ->forward(stdClass::class)
            ->build();

        $callCount = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 2) {
                    throw new RuntimeException('Failed');
                }

                return [];
            },
        );

        $failedIds = [];
        $stepStorage = $this->createMock(SagaStepResultStorageInterface::class);
        $stepStorage->method('record');
        $stepStorage->method('markCompleted');
        $stepStorage->expects(self::atLeastOnce())
            ->method('updateStatus')
            ->willReturnCallback(static function (string $id, SagaStepStatus $status, ?string $error) use (&$failedIds): void {
                if ($status === SagaStepStatus::Failed) {
                    $failedIds[] = $id;
                }
            });

        $orchestrator = $this->createOrchestratorWith(stepResultStorage: $stepStorage);
        $orchestrator->execute($definition, []);

        self::assertCount(1, $failedIds);
    }

    #[Test]
    public function test_execute_records_skipped_irreversible_step_during_compensation(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->step('send_email')
                ->forward(stdClass::class)
                ->irreversible()
            ->step('finalize')
                ->forward(stdClass::class)
            ->build();

        $callCount = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 3) {
                    throw new RuntimeException('Finalization error');
                }

                return [];
            },
        );

        $recorded = [];
        $stepStorage = $this->createMock(SagaStepResultStorageInterface::class);
        $stepStorage->expects(self::atLeastOnce())
            ->method('record')
            ->willReturnCallback(static function (SagaStepResultRecord $record) use (&$recorded): void {
                $recorded[] = $record;
            });
        $stepStorage->method('markCompleted');
        $stepStorage->method('updateStatus');

        $orchestrator = $this->createOrchestratorWith(stepResultStorage: $stepStorage);
        $orchestrator->execute($definition, []);

        // Find the skipped compensation entry
        $skippedRecords = array_values(array_filter(
            $recorded,
            static fn(SagaStepResultRecord $r): bool => $r->status === SagaStepStatus::Skipped,
        ));
        self::assertCount(1, $skippedRecords);
        self::assertSame('send_email', $skippedRecords[0]->stepName);
        self::assertSame(SagaStepDirection::Compensating, $skippedRecords[0]->direction);
    }

    #[Test]
    public function test_execute_without_step_result_storage_works(): void
    {
        $definition = SagaDefinitionBuilder::create('simple')
            ->step('charge')
                ->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        // Orchestrator without step result storage (null)
        $orchestrator = $this->createOrchestrator();
        $state = $orchestrator->execute($definition, []);

        self::assertSame(SagaStatus::Completed, $state->status);
    }

    #[Test]
    public function test_execute_records_idempotency_key_in_step_result(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
                ->forwardIdempotencyKey(static function (array $ctx): string {
                    /** @var string $orderId */
                    $orderId = $ctx['orderId'];

                    return 'charge-' . $orderId;
                })
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $recorded = [];
        $stepStorage = $this->createMock(SagaStepResultStorageInterface::class);
        $stepStorage->expects(self::once())
            ->method('record')
            ->willReturnCallback(static function (SagaStepResultRecord $record) use (&$recorded): void {
                $recorded[] = $record;
            });
        $stepStorage->method('markCompleted');

        $orchestrator = $this->createOrchestratorWith(stepResultStorage: $stepStorage);
        $orchestrator->execute($definition, ['orderId' => 'ORD-42']);

        self::assertCount(1, $recorded);
        self::assertSame('charge-ORD-42', $recorded[0]->idempotencyKey);
    }
}
