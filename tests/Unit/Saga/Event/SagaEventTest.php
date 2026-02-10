<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Event\IrreversibleSagaFailureEvent;
use Pulsar\Saga\Event\SagaCompensationCompletedEvent;
use Pulsar\Saga\Event\SagaCompensationStartedEvent;
use Pulsar\Saga\Event\SagaCompletedEvent;
use Pulsar\Saga\Event\SagaFailedEvent;
use Pulsar\Saga\Event\SagaStartedEvent;
use Pulsar\Saga\Event\SagaStepCompletedEvent;
use Pulsar\Saga\Event\SagaStepFailedEvent;

#[CoversClass(SagaStartedEvent::class)]
#[CoversClass(SagaCompletedEvent::class)]
#[CoversClass(SagaFailedEvent::class)]
#[CoversClass(SagaStepCompletedEvent::class)]
#[CoversClass(SagaStepFailedEvent::class)]
#[CoversClass(SagaCompensationStartedEvent::class)]
#[CoversClass(SagaCompensationCompletedEvent::class)]
#[CoversClass(IrreversibleSagaFailureEvent::class)]
final class SagaEventTest extends TestCase
{
    #[Test]
    public function test_saga_started_event(): void
    {
        $now = new DateTimeImmutable();
        $event = new SagaStartedEvent(
            sagaId: 'saga-1',
            definitionId: 'order_fulfillment',
            definitionVersion: 2,
            totalSteps: 3,
            occurredAt: $now,
        );

        self::assertSame('saga-1', $event->sagaId);
        self::assertSame('order_fulfillment', $event->definitionId);
        self::assertSame(2, $event->definitionVersion);
        self::assertSame(3, $event->totalSteps);
        self::assertSame($now, $event->occurredAt);
    }

    #[Test]
    public function test_saga_completed_event(): void
    {
        $now = new DateTimeImmutable();
        $event = new SagaCompletedEvent(
            sagaId: 'saga-1',
            definitionId: 'order_fulfillment',
            totalSteps: 3,
            occurredAt: $now,
        );

        self::assertSame('saga-1', $event->sagaId);
        self::assertSame('order_fulfillment', $event->definitionId);
        self::assertSame(3, $event->totalSteps);
        self::assertSame($now, $event->occurredAt);
    }

    #[Test]
    public function test_saga_failed_event(): void
    {
        $now = new DateTimeImmutable();
        $event = new SagaFailedEvent(
            sagaId: 'saga-1',
            definitionId: 'order_fulfillment',
            failedStepName: 'charge_payment',
            errorMessage: 'Payment declined',
            compensationSuccessful: true,
            occurredAt: $now,
        );

        self::assertSame('saga-1', $event->sagaId);
        self::assertSame('order_fulfillment', $event->definitionId);
        self::assertSame('charge_payment', $event->failedStepName);
        self::assertSame('Payment declined', $event->errorMessage);
        self::assertTrue($event->compensationSuccessful);
        self::assertSame($now, $event->occurredAt);
    }

    #[Test]
    public function test_saga_failed_event_unsuccessful_compensation(): void
    {
        $event = new SagaFailedEvent(
            sagaId: 'saga-1',
            definitionId: 'test',
            failedStepName: 'charge',
            errorMessage: 'Error',
            compensationSuccessful: false,
            occurredAt: new DateTimeImmutable(),
        );

        self::assertFalse($event->compensationSuccessful);
    }

    #[Test]
    public function test_saga_step_completed_event(): void
    {
        $now = new DateTimeImmutable();
        $output = ['transactionId' => 'txn_123'];
        $event = new SagaStepCompletedEvent(
            sagaId: 'saga-1',
            stepName: 'charge_payment',
            stepIndex: 0,
            output: $output,
            occurredAt: $now,
        );

        self::assertSame('saga-1', $event->sagaId);
        self::assertSame('charge_payment', $event->stepName);
        self::assertSame(0, $event->stepIndex);
        self::assertSame($output, $event->output);
        self::assertSame($now, $event->occurredAt);
    }

    #[Test]
    public function test_saga_step_failed_event(): void
    {
        $now = new DateTimeImmutable();
        $event = new SagaStepFailedEvent(
            sagaId: 'saga-1',
            stepName: 'charge_payment',
            stepIndex: 0,
            errorMessage: 'Payment service unavailable',
            occurredAt: $now,
        );

        self::assertSame('saga-1', $event->sagaId);
        self::assertSame('charge_payment', $event->stepName);
        self::assertSame(0, $event->stepIndex);
        self::assertSame('Payment service unavailable', $event->errorMessage);
        self::assertSame($now, $event->occurredAt);
    }

    #[Test]
    public function test_saga_compensation_started_event(): void
    {
        $now = new DateTimeImmutable();
        $event = new SagaCompensationStartedEvent(
            sagaId: 'saga-1',
            failedStepName: 'charge_payment',
            stepsToCompensate: 2,
            occurredAt: $now,
        );

        self::assertSame('saga-1', $event->sagaId);
        self::assertSame('charge_payment', $event->failedStepName);
        self::assertSame(2, $event->stepsToCompensate);
        self::assertSame($now, $event->occurredAt);
    }

    #[Test]
    public function test_saga_compensation_completed_event(): void
    {
        $now = new DateTimeImmutable();
        $event = new SagaCompensationCompletedEvent(
            sagaId: 'saga-1',
            stepsCompensated: 2,
            occurredAt: $now,
        );

        self::assertSame('saga-1', $event->sagaId);
        self::assertSame(2, $event->stepsCompensated);
        self::assertSame($now, $event->occurredAt);
    }

    #[Test]
    public function test_irreversible_saga_failure_event(): void
    {
        $now = new DateTimeImmutable();
        $event = new IrreversibleSagaFailureEvent(
            sagaId: 'saga-1',
            definitionId: 'order_fulfillment',
            completedIrreversibleSteps: ['send_email', 'notify_partner'],
            failedStepName: 'finalize',
            errorMessage: 'Finalization failed',
            operatorActionHint: 'Review irreversible steps and take manual action',
            occurredAt: $now,
        );

        self::assertSame('saga-1', $event->sagaId);
        self::assertSame('order_fulfillment', $event->definitionId);
        self::assertSame(['send_email', 'notify_partner'], $event->completedIrreversibleSteps);
        self::assertSame('finalize', $event->failedStepName);
        self::assertSame('Finalization failed', $event->errorMessage);
        self::assertSame('Review irreversible steps and take manual action', $event->operatorActionHint);
        self::assertSame($now, $event->occurredAt);
    }

    #[Test]
    public function test_irreversible_saga_failure_event_has_operator_action_hint(): void
    {
        $event = new IrreversibleSagaFailureEvent(
            sagaId: 'saga-1',
            definitionId: 'test',
            completedIrreversibleSteps: ['send_email'],
            failedStepName: 'next_step',
            errorMessage: 'Error',
            operatorActionHint: 'Manually revert the email send',
            occurredAt: new DateTimeImmutable(),
        );

        self::assertNotEmpty($event->operatorActionHint);
        self::assertStringContainsString('revert', $event->operatorActionHint);
    }
}
