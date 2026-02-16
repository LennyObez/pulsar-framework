<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Internal;

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
use Pulsar\Saga\SagaDefinition;
use Pulsar\Saga\SagaDefinitionBuilder;
use Pulsar\Saga\SagaState;
use Pulsar\Saga\SagaStateStorageInterface;
use Pulsar\Saga\SagaStatus;
use Pulsar\Saga\SagaStepResultStorageInterface;
use Pulsar\Saga\Step\SagaStep;
use Pulsar\Saga\Step\SagaStepDirection;
use Pulsar\Saga\Step\SagaStepResult as SagaStepResultRecord;
use Pulsar\Saga\Step\SagaStepStatus;
use Pulsar\Saga\Step\StepResult;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use stdClass;

use function array_filter;
use function array_map;
use function array_values;
use function assert;
use function count;
use function is_string;
use function strlen;

/**
 * Comprehensive unit tests for SagaOrchestrator focusing on:
 * - Forward execution path with context propagation
 * - Compensation in reverse order on failure
 * - Irreversible step handling during compensation
 * - Retry policy enforcement
 * - Resume from interrupted states
 * - Force compensate semantics
 * - Step result storage recording
 * - Event dispatch correctness
 * - Edge cases (empty saga, single step, all steps irreversible)
 */
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

    private function orchestrator(
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

    /**
     * Capture all events dispatched through an EventDispatcherInterface mock.
     *
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

    /**
     * @return list<SagaState>
     */
    private function &captureStorageSaves(): array
    {
        $saved = [];
        $storage = $this->createMock(SagaStateStorageInterface::class);
        $storage->expects(self::atLeastOnce())
            ->method('save')
            ->willReturnCallback(static function (SagaState $s) use (&$saved): void {
                $saved[] = $s;
            });
        $this->storage = $storage;

        return $saved;
    }

    // =========================================================================
    // execute() — happy path
    // =========================================================================

    #[Test]
    public function execute_completes_all_forward_steps_successfully(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('reserve')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('confirm')->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $state = $this->orchestrator()->execute($definition, ['orderId' => 'ORD-1']);

        self::assertSame(SagaStatus::Completed, $state->status);
        self::assertSame(3, $state->currentStepIndex);
        self::assertCount(3, $state->stepResults);
        self::assertNotNull($state->completedAt);
    }

    #[Test]
    public function execute_propagates_step_output_into_saga_context(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)
            ->step('confirm')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;

                return $call === 1 ? ['transactionId' => 'txn_abc', 'fee' => 250] : [];
            },
        );

        $state = $this->orchestrator()->execute($definition, ['orderId' => 'ORD-1']);

        self::assertSame('txn_abc', $state->context['transactionId']);
        self::assertSame(250, $state->context['fee']);
        self::assertSame('ORD-1', $state->context['orderId']);
    }

    #[Test]
    public function execute_preserves_original_context_when_step_returns_empty_output(): void
    {
        $definition = SagaDefinitionBuilder::create('simple')
            ->step('noop')->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $state = $this->orchestrator()->execute($definition, ['key' => 'value']);

        self::assertSame(['key' => 'value'], $state->context);
    }

    #[Test]
    public function execute_generates_unique_saga_id(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('s')->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $state = $this->orchestrator()->execute($definition, []);

        self::assertNotEmpty($state->sagaId);
        self::assertSame(32, strlen($state->sagaId)); // 16 bytes -> 32 hex chars
    }

    #[Test]
    public function execute_validates_definition_before_running(): void
    {
        $emptyDefinition = new SagaDefinition(name: 'empty', steps: []);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('no steps defined');

        $this->orchestrator()->execute($emptyDefinition, []);
    }

    // =========================================================================
    // execute() — state persistence
    // =========================================================================

    #[Test]
    public function execute_persists_state_after_each_step_and_on_completion(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('a')->forward(stdClass::class)
            ->step('b')->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $saved = &$this->captureStorageSaves();
        $this->orchestrator()->execute($definition, []);

        // 1=initial, 2=after step a, 3=after step b, 4=completed
        self::assertGreaterThanOrEqual(4, count($saved));

        // First save should be Running (initial)
        self::assertSame(SagaStatus::Running, $saved[0]->status);

        // Last save should be Completed
        self::assertSame(SagaStatus::Completed, $saved[count($saved) - 1]->status);
    }

    // =========================================================================
    // execute() — event dispatch
    // =========================================================================

    #[Test]
    public function execute_dispatches_started_step_completed_and_completed_events(): void
    {
        $definition = SagaDefinitionBuilder::create('multi')
            ->step('a')->forward(stdClass::class)
            ->step('b')->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $events = &$this->captureEvents();
        $this->orchestrator()->execute($definition, []);

        $started = array_filter($events, static fn(object $e): bool => $e instanceof SagaStartedEvent);
        $stepCompleted = array_filter($events, static fn(object $e): bool => $e instanceof SagaStepCompletedEvent);
        $completed = array_filter($events, static fn(object $e): bool => $e instanceof SagaCompletedEvent);

        self::assertCount(1, $started);
        self::assertCount(2, $stepCompleted);
        self::assertCount(1, $completed);
    }

    #[Test]
    public function execute_started_event_contains_definition_metadata(): void
    {
        $definition = SagaDefinitionBuilder::create('order_flow')
            ->step('a')->forward(stdClass::class)
            ->step('b')->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $events = &$this->captureEvents();
        $this->orchestrator()->execute($definition, []);

        /** @var SagaStartedEvent $started */
        $started = array_values(array_filter($events, static fn(object $e): bool => $e instanceof SagaStartedEvent))[0];
        self::assertSame('order_flow', $started->definitionId);
        self::assertSame(1, $started->definitionVersion);
        self::assertSame(2, $started->totalSteps);
        self::assertInstanceOf(DateTimeImmutable::class, $started->occurredAt);
    }

    #[Test]
    public function execute_step_completed_events_carry_step_output(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn(['txn' => 'T1']);

        $events = &$this->captureEvents();
        $this->orchestrator()->execute($definition, []);

        /** @var SagaStepCompletedEvent $event */
        $event = array_values(array_filter($events, static fn(object $e): bool => $e instanceof SagaStepCompletedEvent))[0];
        self::assertSame('charge', $event->stepName);
        self::assertSame(0, $event->stepIndex);
        self::assertSame(['txn' => 'T1'], $event->output);
    }

    // =========================================================================
    // execute() — failure and compensation
    // =========================================================================

    #[Test]
    public function execute_compensates_in_reverse_order_on_forward_failure(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('reserve')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('ship')->forward(stdClass::class)
            ->build();

        $calls = [];
        $callCount = 0;
        $commandBus = $this->createMock(CommandBusPort::class);
        $commandBus->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (string $action, array $ctx, ?string $key) use (&$callCount, &$calls): array {
                $callCount++;
                $calls[] = $callCount;
                if ($callCount === 3) {
                    throw new RuntimeException('Ship failed');
                }

                return [];
            });

        $state = $this->orchestrator(commandBus: $commandBus)->execute($definition, ['orderId' => 'O1']);

        self::assertSame(SagaStatus::Failed, $state->status);
        // Forward: charge(1), reserve(2), ship(3 fails)
        // Compensation: reserve(4), charge(5) in reverse order
        self::assertSame(5, $callCount);
    }

    #[Test]
    public function execute_failure_dispatches_step_failed_compensation_and_saga_failed_events(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('ok')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('fail_step')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;
                if ($call === 2) {
                    throw new RuntimeException('Boom');
                }

                return [];
            },
        );

        $events = &$this->captureEvents();
        $this->orchestrator()->execute($definition, []);

        $stepFailed = array_values(array_filter($events, static fn(object $e): bool => $e instanceof SagaStepFailedEvent));
        $compStarted = array_values(array_filter($events, static fn(object $e): bool => $e instanceof SagaCompensationStartedEvent));
        $compCompleted = array_values(array_filter($events, static fn(object $e): bool => $e instanceof SagaCompensationCompletedEvent));
        $sagaFailed = array_values(array_filter($events, static fn(object $e): bool => $e instanceof SagaFailedEvent));

        self::assertCount(1, $stepFailed);
        self::assertSame('fail_step', $stepFailed[0]->stepName);
        self::assertSame(1, $stepFailed[0]->stepIndex);
        self::assertStringContainsString('Boom', $stepFailed[0]->errorMessage);

        self::assertCount(1, $compStarted);
        self::assertCount(1, $compCompleted);
        self::assertCount(1, $sagaFailed);
        self::assertSame('fail_step', $sagaFailed[0]->failedStepName);
    }

    #[Test]
    public function execute_first_step_failure_compensates_zero_steps(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('fail_first')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('never_reached')->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willThrowException(new RuntimeException('Immediate fail'));

        $state = $this->orchestrator()->execute($definition, []);

        self::assertSame(SagaStatus::Failed, $state->status);
        self::assertSame(0, $state->currentStepIndex);
        self::assertCount(0, $state->stepResults);
    }

    #[Test]
    public function execute_sets_compensating_state_before_running_compensation(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('ok')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('fail')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;
                if ($call === 2) {
                    throw new RuntimeException('Fail');
                }

                return [];
            },
        );

        $saved = &$this->captureStorageSaves();
        $this->orchestrator()->execute($definition, []);

        $statuses = array_map(static fn(SagaState $s): SagaStatus => $s->status, $saved);
        self::assertContains(SagaStatus::Compensating, $statuses);
        self::assertSame(SagaStatus::Failed, $saved[count($saved) - 1]->status);
    }

    // =========================================================================
    // execute() — steps without compensation
    // =========================================================================

    #[Test]
    public function execute_skips_steps_without_compensation_action(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('log_audit')->forward(stdClass::class) // no compensate()
            ->step('will_fail')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;
                if ($call === 2) {
                    throw new RuntimeException('Fail');
                }

                return [];
            },
        );

        $state = $this->orchestrator()->execute($definition, []);

        self::assertSame(SagaStatus::Failed, $state->status);
        // Forward: log_audit(1), will_fail(2 fails). Compensation: 0 calls (no compensation defined)
        self::assertSame(2, $call);
    }

    // =========================================================================
    // execute() — irreversible steps
    // =========================================================================

    #[Test]
    public function execute_skips_irreversible_steps_during_compensation(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('email')->forward(stdClass::class)->irreversible()
            ->step('finalize')->forward(stdClass::class)
            ->build();

        $call = 0;
        $commandBus = $this->createMock(CommandBusPort::class);
        $commandBus->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function () use (&$call): array {
                $call++;
                if ($call === 3) {
                    throw new RuntimeException('Finalization error');
                }

                return [];
            });

        $events = &$this->captureEvents();
        $state = $this->orchestrator(commandBus: $commandBus)->execute($definition, []);

        self::assertSame(SagaStatus::Failed, $state->status);
        // Forward: charge(1), email(2), finalize(3 fails). Compensation: charge(4). Email skipped.
        self::assertSame(4, $call);

        // IrreversibleSagaFailureEvent should be dispatched
        $irreversible = array_values(array_filter(
            $events,
            static fn(object $e): bool => $e instanceof IrreversibleSagaFailureEvent,
        ));
        self::assertCount(1, $irreversible);
        self::assertSame(['email'], $irreversible[0]->completedIrreversibleSteps);
        self::assertStringContainsString('email', $irreversible[0]->operatorActionHint);
    }

    #[Test]
    public function execute_no_irreversible_event_when_no_irreversible_steps_present(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('ok')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('fail')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;
                if ($call === 2) {
                    throw new RuntimeException('Fail');
                }

                return [];
            },
        );

        $events = &$this->captureEvents();
        $this->orchestrator()->execute($definition, []);

        $irreversible = array_filter(
            $events,
            static fn(object $e): bool => $e instanceof IrreversibleSagaFailureEvent,
        );
        self::assertCount(0, $irreversible);
    }

    #[Test]
    public function execute_multiple_irreversible_steps_all_reported(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('sms')->forward(stdClass::class)->irreversible()
            ->step('email')->forward(stdClass::class)->irreversible()
            ->step('finalize')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;
                if ($call === 4) {
                    throw new RuntimeException('Fail');
                }

                return [];
            },
        );

        $events = &$this->captureEvents();
        $this->orchestrator()->execute($definition, []);

        $irreversible = array_values(array_filter(
            $events,
            static fn(object $e): bool => $e instanceof IrreversibleSagaFailureEvent,
        ));
        self::assertCount(1, $irreversible);
        self::assertContains('sms', $irreversible[0]->completedIrreversibleSteps);
        self::assertContains('email', $irreversible[0]->completedIrreversibleSteps);
    }

    // =========================================================================
    // execute() — compensation failure
    // =========================================================================

    #[Test]
    public function execute_compensation_failure_throws_CompensationFailedException(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('fail_step')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;
                if ($call === 2) {
                    throw new RuntimeException('Forward fail');
                }
                if ($call === 3) {
                    throw new RuntimeException('Compensation fail');
                }

                return [];
            },
        );

        $this->expectException(CompensationFailedException::class);
        $this->expectExceptionMessage('charge');
        $this->expectExceptionMessage('Compensation fail');

        $this->orchestrator()->execute($definition, []);
    }

    #[Test]
    public function execute_compensation_failure_saves_failed_state(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('fail_step')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;
                if ($call === 2) {
                    throw new RuntimeException('Forward fail');
                }
                if ($call === 3) {
                    throw new RuntimeException('Compensation fail');
                }

                return [];
            },
        );

        $saved = &$this->captureStorageSaves();

        try {
            $this->orchestrator()->execute($definition, []);
        } catch (CompensationFailedException) {
            // Expected
        }

        $lastState = $saved[count($saved) - 1];
        self::assertSame(SagaStatus::Failed, $lastState->status);
    }

    #[Test]
    public function execute_compensation_failure_with_irreversible_steps_dispatches_irreversible_event(): void
    {
        // Order: charge, reserve, email(irreversible), finalize(fails)
        // Reverse compensation: email(skipped, irreversible), reserve(compensation fails)
        // At the point reserve compensation fails, email was already recorded as irreversible
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('reserve')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('email')->forward(stdClass::class)->irreversible()
            ->step('finalize')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;
                if ($call === 4) {
                    throw new RuntimeException('Forward fail');
                }
                // Compensation of 'reserve' fails (email is skipped, then reserve is attempted)
                if ($call === 5) {
                    throw new RuntimeException('Compensation fail');
                }

                return [];
            },
        );

        $events = &$this->captureEvents();

        try {
            $this->orchestrator()->execute($definition, []);
        } catch (CompensationFailedException) {
            // Expected
        }

        $irreversible = array_values(array_filter(
            $events,
            static fn(object $e): bool => $e instanceof IrreversibleSagaFailureEvent,
        ));
        self::assertCount(1, $irreversible);
        self::assertContains('email', $irreversible[0]->completedIrreversibleSteps);
    }

    // =========================================================================
    // execute() — idempotency keys
    // =========================================================================

    #[Test]
    public function execute_forward_idempotency_key_passed_to_command_bus(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
                ->forwardIdempotencyKey(static function (array $ctx): string {
                    $orderId = $ctx['orderId'];
                    assert(is_string($orderId));
                    return 'charge-' . $orderId;
                })
            ->build();

        $receivedKey = null;
        $commandBus = $this->createMock(CommandBusPort::class);
        $commandBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (string $a, array $c, ?string $key) use (&$receivedKey): array {
                $receivedKey = $key;

                return [];
            });

        $this->orchestrator(commandBus: $commandBus)->execute($definition, ['orderId' => 'ORD-99']);

        self::assertSame('charge-ORD-99', $receivedKey);
    }

    #[Test]
    public function execute_compensation_idempotency_key_passed_to_command_bus(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
                ->compensationIdempotencyKey(static function (array $ctx): string {
                    $orderId = $ctx['orderId'];
                    assert(is_string($orderId));
                    return 'refund-' . $orderId;
                })
            ->step('fail')
                ->forward(stdClass::class)
            ->build();

        $call = 0;
        $compensationKey = null;
        $commandBus = $this->createMock(CommandBusPort::class);
        $commandBus->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function (string $a, array $c, ?string $key) use (&$call, &$compensationKey): array {
                $call++;
                if ($call === 2) {
                    throw new RuntimeException('Fail');
                }
                if ($call === 3) {
                    $compensationKey = $key;
                }

                return [];
            });

        $this->orchestrator(commandBus: $commandBus)->execute($definition, ['orderId' => 'ORD-42']);

        self::assertSame('refund-ORD-42', $compensationKey);
    }

    #[Test]
    public function execute_null_idempotency_key_when_not_configured(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('simple')->forward(stdClass::class)
            ->build();

        $receivedKey = 'sentinel';
        $commandBus = $this->createMock(CommandBusPort::class);
        $commandBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (string $a, array $c, ?string $key) use (&$receivedKey): array {
                $receivedKey = $key;

                return [];
            });

        $this->orchestrator(commandBus: $commandBus)->execute($definition, []);

        self::assertNull($receivedKey);
    }

    // =========================================================================
    // execute() — retry policy
    // =========================================================================

    #[Test]
    public function execute_retries_forward_step_per_retry_policy(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('flaky')
                ->forward(stdClass::class)
                ->retryPolicy(maxAttempts: 3, initialDelayMs: 0)
            ->build();

        $call = 0;
        $commandBus = $this->createMock(CommandBusPort::class);
        $commandBus->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(static function () use (&$call): array {
                $call++;
                if ($call < 3) {
                    throw new RuntimeException("Attempt $call failed");
                }

                return ['success' => true];
            });

        $state = $this->orchestrator(commandBus: $commandBus)->execute($definition, []);

        self::assertSame(SagaStatus::Completed, $state->status);
        self::assertSame(['success' => true], $state->context);
    }

    #[Test]
    public function execute_exhausts_all_retries_then_triggers_compensation(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('always_fail')
                ->forward(stdClass::class)
                ->retryPolicy(maxAttempts: 2, initialDelayMs: 0)
            ->build();

        $this->commandBus->method('dispatch')->willThrowException(new RuntimeException('Permanent failure'));

        $state = $this->orchestrator()->execute($definition, []);

        self::assertSame(SagaStatus::Failed, $state->status);
    }

    #[Test]
    public function execute_compensation_retries_per_compensation_retry_policy(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
                ->compensationRetryPolicy(maxAttempts: 3, initialDelayMs: 0)
            ->step('fail_step')
                ->forward(stdClass::class)
            ->build();

        $call = 0;
        $commandBus = $this->createMock(CommandBusPort::class);
        $commandBus->expects(self::atLeastOnce())
            ->method('dispatch')
            ->willReturnCallback(static function () use (&$call): array {
                $call++;
                if ($call === 2) {
                    throw new RuntimeException('Forward fail');
                }
                // Compensation retries: calls 3 and 4 fail, call 5 succeeds
                if ($call === 3 || $call === 4) {
                    throw new RuntimeException('Compensation retry');
                }

                return [];
            });

        $state = $this->orchestrator(commandBus: $commandBus)->execute($definition, []);

        self::assertSame(SagaStatus::Failed, $state->status);
    }

    // =========================================================================
    // resume()
    // =========================================================================

    #[Test]
    public function resume_continues_forward_from_last_saved_step(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)
            ->step('confirm')->forward(stdClass::class)
            ->build();

        $interrupted = new SagaState(
            sagaId: 'saga-123',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 1,
            stepResults: [StepResult::success('charge')],
            status: SagaStatus::Running,
            context: ['orderId' => 'ORD-1'],
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );

        $this->storage->method('findById')->willReturn($interrupted);
        $this->commandBus->method('dispatch')->willReturn([]);

        $state = $this->orchestrator()->resume('saga-123', $definition);

        self::assertSame(SagaStatus::Completed, $state->status);
        self::assertSame(2, $state->currentStepIndex);
        self::assertCount(2, $state->stepResults);
    }

    #[Test]
    public function resume_compensating_saga_continues_compensation(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('reserve')->forward(stdClass::class)->compensate(stdClass::class)
            ->build();

        $compensating = new SagaState(
            sagaId: 'saga-comp',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 2,
            stepResults: [StepResult::success('charge'), StepResult::success('reserve')],
            status: SagaStatus::Compensating,
            context: [],
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );

        $this->storage->method('findById')->willReturn($compensating);
        $this->commandBus->method('dispatch')->willReturn([]);

        $state = $this->orchestrator()->resume('saga-comp', $definition);

        self::assertSame(SagaStatus::Failed, $state->status);
    }

    #[Test]
    public function resume_completed_saga_throws(): void
    {
        $completed = new SagaState(
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

        $this->storage->method('findById')->willReturn($completed);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('running');

        $this->orchestrator()->resume('saga-done', SagaDefinitionBuilder::create('t')
            ->step('s')->forward(stdClass::class)->build());
    }

    #[Test]
    public function resume_failed_saga_throws(): void
    {
        $failed = new SagaState(
            sagaId: 'saga-fail',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 1,
            stepResults: [],
            status: SagaStatus::Failed,
            context: [],
            startedAt: new DateTimeImmutable(),
            completedAt: new DateTimeImmutable(),
        );

        $this->storage->method('findById')->willReturn($failed);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('running');

        $this->orchestrator()->resume('saga-fail', SagaDefinitionBuilder::create('t')
            ->step('s')->forward(stdClass::class)->build());
    }

    #[Test]
    public function resume_nonexistent_saga_throws(): void
    {
        $this->storage->method('findById')->willReturn(null);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('not found');

        $this->orchestrator()->resume('nonexistent', SagaDefinitionBuilder::create('t')
            ->step('s')->forward(stdClass::class)->build());
    }

    // =========================================================================
    // compensate()
    // =========================================================================

    #[Test]
    public function compensate_running_saga_triggers_compensation(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('reserve')->forward(stdClass::class)->compensate(stdClass::class)
            ->build();

        $running = new SagaState(
            sagaId: 'saga-running',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 2,
            stepResults: [StepResult::success('charge'), StepResult::success('reserve')],
            status: SagaStatus::Running,
            context: [],
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );

        $this->storage->method('findById')->willReturn($running);
        $this->commandBus->method('dispatch')->willReturn([]);

        $state = $this->orchestrator()->compensate('saga-running', $definition);

        self::assertSame(SagaStatus::Failed, $state->status);
    }

    #[Test]
    public function compensate_non_running_saga_throws(): void
    {
        $completed = new SagaState(
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

        $this->storage->method('findById')->willReturn($completed);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('running');

        $this->orchestrator()->compensate('saga-done', SagaDefinitionBuilder::create('t')
            ->step('s')->forward(stdClass::class)->build());
    }

    #[Test]
    public function compensate_compensating_saga_throws(): void
    {
        $compensating = new SagaState(
            sagaId: 'saga-comp',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 1,
            stepResults: [StepResult::success('charge')],
            status: SagaStatus::Compensating,
            context: [],
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );

        $this->storage->method('findById')->willReturn($compensating);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('running');

        $this->orchestrator()->compensate('saga-comp', SagaDefinitionBuilder::create('t')
            ->step('s')->forward(stdClass::class)->build());
    }

    #[Test]
    public function compensate_saves_compensating_state_then_failed_state(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)->compensate(stdClass::class)
            ->build();

        $running = new SagaState(
            sagaId: 'saga-running',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 1,
            stepResults: [StepResult::success('charge')],
            status: SagaStatus::Running,
            context: [],
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );

        $this->commandBus->method('dispatch')->willReturn([]);

        $saved = &$this->captureStorageSaves();
        $this->storage->method('findById')->willReturn($running);

        $this->orchestrator()->compensate('saga-running', $definition);

        $statuses = array_map(static fn(SagaState $s): SagaStatus => $s->status, $saved);
        self::assertContains(SagaStatus::Compensating, $statuses);
        self::assertContains(SagaStatus::Failed, $statuses);
    }

    #[Test]
    public function compensate_not_found_throws(): void
    {
        $this->storage->method('findById')->willReturn(null);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('not found');

        $this->orchestrator()->compensate('missing', SagaDefinitionBuilder::create('t')
            ->step('s')->forward(stdClass::class)->build());
    }

    // =========================================================================
    // Step result storage integration
    // =========================================================================

    #[Test]
    public function execute_records_forward_step_results_when_storage_provided(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('a')->forward(stdClass::class)
            ->step('b')->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn(['x' => 1]);

        $recorded = [];
        $stepStorage = $this->createMock(SagaStepResultStorageInterface::class);
        $stepStorage->expects(self::atLeastOnce())
            ->method('record')
            ->willReturnCallback(static function (SagaStepResultRecord $r) use (&$recorded): void {
                $recorded[] = $r;
            });
        $stepStorage->expects(self::atLeastOnce())
            ->method('markCompleted');

        $this->orchestrator(stepResultStorage: $stepStorage)->execute($definition, []);

        self::assertCount(2, $recorded);
        self::assertSame('a', $recorded[0]->stepName);
        self::assertSame(0, $recorded[0]->stepIndex);
        self::assertSame(SagaStepDirection::Forward, $recorded[0]->direction);
        self::assertSame(SagaStepStatus::Running, $recorded[0]->status);
        self::assertSame(1, $recorded[0]->attempts);
        self::assertSame('b', $recorded[1]->stepName);
        self::assertSame(1, $recorded[1]->stepIndex);
    }

    #[Test]
    public function execute_records_failed_step_status(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('ok')->forward(stdClass::class)
            ->step('fail')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;
                if ($call === 2) {
                    throw new RuntimeException('Boom');
                }

                return [];
            },
        );

        $failedStatuses = [];
        $stepStorage = $this->createMock(SagaStepResultStorageInterface::class);
        $stepStorage->method('record');
        $stepStorage->method('markCompleted');
        $stepStorage->expects(self::atLeastOnce())
            ->method('updateStatus')
            ->willReturnCallback(static function (string $id, SagaStepStatus $status, ?string $error) use (&$failedStatuses): void {
                if ($status === SagaStepStatus::Failed) {
                    $failedStatuses[] = ['id' => $id, 'error' => $error];
                }
            });

        $this->orchestrator(stepResultStorage: $stepStorage)->execute($definition, []);

        self::assertCount(1, $failedStatuses);
        $error = $failedStatuses[0]['error'];
        self::assertNotNull($error);
        self::assertStringContainsString('Boom', $error);
    }

    #[Test]
    public function execute_records_skipped_irreversible_step_during_compensation(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('email')->forward(stdClass::class)->irreversible()
            ->step('finalize')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;
                if ($call === 3) {
                    throw new RuntimeException('Error');
                }

                return [];
            },
        );

        $recorded = [];
        $stepStorage = $this->createMock(SagaStepResultStorageInterface::class);
        $stepStorage->expects(self::atLeastOnce())
            ->method('record')
            ->willReturnCallback(static function (SagaStepResultRecord $r) use (&$recorded): void {
                $recorded[] = $r;
            });
        $stepStorage->method('markCompleted');
        $stepStorage->method('updateStatus');

        $this->orchestrator(stepResultStorage: $stepStorage)->execute($definition, []);

        $skipped = array_values(array_filter(
            $recorded,
            static fn(SagaStepResultRecord $r): bool => $r->status === SagaStepStatus::Skipped,
        ));
        self::assertCount(1, $skipped);
        self::assertSame('email', $skipped[0]->stepName);
        self::assertSame(SagaStepDirection::Compensating, $skipped[0]->direction);
        self::assertSame(0, $skipped[0]->attempts);
    }

    #[Test]
    public function execute_records_compensation_steps(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('fail')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;
                if ($call === 2) {
                    throw new RuntimeException('Fail');
                }

                return [];
            },
        );

        $recorded = [];
        $stepStorage = $this->createMock(SagaStepResultStorageInterface::class);
        $stepStorage->expects(self::atLeastOnce())
            ->method('record')
            ->willReturnCallback(static function (SagaStepResultRecord $r) use (&$recorded): void {
                $recorded[] = $r;
            });
        $stepStorage->method('markCompleted');
        $stepStorage->method('updateStatus');

        $this->orchestrator(stepResultStorage: $stepStorage)->execute($definition, []);

        $compensating = array_values(array_filter(
            $recorded,
            static fn(SagaStepResultRecord $r): bool => $r->direction === SagaStepDirection::Compensating && $r->status === SagaStepStatus::Running,
        ));
        self::assertCount(1, $compensating);
        self::assertSame('charge', $compensating[0]->stepName);
    }

    #[Test]
    public function execute_records_idempotency_key_in_step_result(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
                ->forwardIdempotencyKey(static function (array $ctx): string {
                    $id = $ctx['id'];
                    assert(is_string($id));
                    return 'key-' . $id;
                })
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $recorded = [];
        $stepStorage = $this->createMock(SagaStepResultStorageInterface::class);
        $stepStorage->expects(self::once())
            ->method('record')
            ->willReturnCallback(static function (SagaStepResultRecord $r) use (&$recorded): void {
                $recorded[] = $r;
            });
        $stepStorage->method('markCompleted');

        $this->orchestrator(stepResultStorage: $stepStorage)->execute($definition, ['id' => 'X']);

        self::assertSame('key-X', $recorded[0]->idempotencyKey);
    }

    #[Test]
    public function execute_without_step_result_storage_works_fine(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('s')->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $state = $this->orchestrator(stepResultStorage: null)->execute($definition, []);

        self::assertSame(SagaStatus::Completed, $state->status);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    #[Test]
    public function execute_single_step_saga_completes(): void
    {
        $definition = SagaDefinitionBuilder::create('one')
            ->step('only')->forward(stdClass::class)
            ->build();

        $this->commandBus->method('dispatch')->willReturn(['result' => 'ok']);

        $state = $this->orchestrator()->execute($definition, []);

        self::assertSame(SagaStatus::Completed, $state->status);
        self::assertSame(1, $state->currentStepIndex);
        self::assertCount(1, $state->stepResults);
        self::assertSame('only', $state->stepResults[0]->stepName);
    }

    #[Test]
    public function execute_step_with_null_forward_action_succeeds(): void
    {
        // Construct manually since builder requires forward
        $steps = [
            'noop' => new SagaStep(
                name: 'noop',
                forwardAction: stdClass::class,
                forwardIdempotencyKey: null,
                compensationAction: null,
                compensationIdempotencyKey: null,
            ),
        ];
        $definition = new SagaDefinition(name: 'test', steps: $steps);

        $this->commandBus->method('dispatch')->willReturn([]);

        $state = $this->orchestrator()->execute($definition, []);

        self::assertSame(SagaStatus::Completed, $state->status);
    }

    #[Test]
    public function execute_large_saga_completes_all_steps(): void
    {
        $builder = SagaDefinitionBuilder::create('large');
        for ($i = 0; $i < 10; $i++) {
            $builder = $builder->step("step_$i")->forward(stdClass::class)->compensate(stdClass::class);
        }
        $definition = $builder->build();

        $this->commandBus->method('dispatch')->willReturn([]);

        $state = $this->orchestrator()->execute($definition, []);

        self::assertSame(SagaStatus::Completed, $state->status);
        self::assertSame(10, $state->currentStepIndex);
        self::assertCount(10, $state->stepResults);
    }

    #[Test]
    public function execute_step_output_merges_additively(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('step1')->forward(stdClass::class)
            ->step('step2')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;

                return match ($call) {
                    1 => ['a' => 1, 'b' => 2],
                    2 => ['b' => 99, 'c' => 3],
                    default => [],
                };
            },
        );

        $state = $this->orchestrator()->execute($definition, ['initial' => 'val']);

        // step2 output 'b' overrides step1 output 'b'
        self::assertSame('val', $state->context['initial']);
        self::assertSame(1, $state->context['a']);
        self::assertSame(99, $state->context['b']);
        self::assertSame(3, $state->context['c']);
    }

    #[Test]
    public function compensate_empty_step_results_compensates_zero_steps(): void
    {
        $definition = SagaDefinitionBuilder::create('order')
            ->step('charge')->forward(stdClass::class)->compensate(stdClass::class)
            ->build();

        $running = new SagaState(
            sagaId: 'saga-empty',
            definitionId: 'order',
            definitionVersion: 1,
            currentStepIndex: 0,
            stepResults: [],
            status: SagaStatus::Running,
            context: [],
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );

        $this->storage->method('findById')->willReturn($running);

        $events = &$this->captureEvents();
        $state = $this->orchestrator()->compensate('saga-empty', $definition);

        self::assertSame(SagaStatus::Failed, $state->status);

        $compCompleted = array_values(array_filter(
            $events,
            static fn(object $e): bool => $e instanceof SagaCompensationCompletedEvent,
        ));
        self::assertCount(1, $compCompleted);
        self::assertSame(0, $compCompleted[0]->stepsCompensated);
    }

    #[Test]
    public function compensation_started_event_names_last_failed_step(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('step_a')->forward(stdClass::class)->compensate(stdClass::class)
            ->step('step_b')->forward(stdClass::class)
            ->build();

        $call = 0;
        $this->commandBus->method('dispatch')->willReturnCallback(
            static function () use (&$call): array {
                $call++;
                if ($call === 2) {
                    throw new RuntimeException('Fail');
                }

                return [];
            },
        );

        $events = &$this->captureEvents();
        $this->orchestrator()->execute($definition, []);

        $compStarted = array_values(array_filter(
            $events,
            static fn(object $e): bool => $e instanceof SagaCompensationStartedEvent,
        ));
        self::assertCount(1, $compStarted);
        self::assertSame('step_a', $compStarted[0]->failedStepName);
        self::assertSame(1, $compStarted[0]->stepsToCompensate);
    }
}
