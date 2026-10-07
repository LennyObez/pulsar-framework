<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Step;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Step\SagaStepDirection;
use Pulsar\Saga\Step\SagaStepResult;
use Pulsar\Saga\Step\SagaStepStatus;

#[CoversClass(SagaStepResult::class)]
final class SagaStepResultRecordTest extends TestCase
{
    #[Test]
    public function test_construction_with_all_fields(): void
    {
        $startedAt = new DateTimeImmutable('2026-03-02 10:00:00');
        $completedAt = new DateTimeImmutable('2026-03-02 10:00:01');
        $resultData = ['transactionId' => 'txn_123'];

        $record = new SagaStepResult(
            id: 'step-result-1',
            instanceId: 'saga-1',
            stepName: 'charge_payment',
            stepIndex: 0,
            direction: SagaStepDirection::Forward,
            status: SagaStepStatus::Completed,
            idempotencyKey: 'charge-ORD-1',
            attempts: 1,
            resultData: $resultData,
            errorMessage: null,
            startedAt: $startedAt,
            completedAt: $completedAt,
        );

        self::assertSame('step-result-1', $record->id);
        self::assertSame('saga-1', $record->instanceId);
        self::assertSame('charge_payment', $record->stepName);
        self::assertSame(0, $record->stepIndex);
        self::assertSame(SagaStepDirection::Forward, $record->direction);
        self::assertSame(SagaStepStatus::Completed, $record->status);
        self::assertSame('charge-ORD-1', $record->idempotencyKey);
        self::assertSame(1, $record->attempts);
        self::assertSame($resultData, $record->resultData);
        self::assertNull($record->errorMessage);
        self::assertSame($startedAt, $record->startedAt);
        self::assertSame($completedAt, $record->completedAt);
    }

    #[Test]
    public function test_construction_with_nullable_fields(): void
    {
        $record = new SagaStepResult(
            id: 'step-result-2',
            instanceId: 'saga-1',
            stepName: 'reserve',
            stepIndex: 1,
            direction: SagaStepDirection::Forward,
            status: SagaStepStatus::Running,
            idempotencyKey: null,
            attempts: 1,
            resultData: null,
            errorMessage: null,
            startedAt: new DateTimeImmutable(),
            completedAt: null,
        );

        self::assertNull($record->idempotencyKey);
        self::assertNull($record->resultData);
        self::assertNull($record->errorMessage);
        self::assertNull($record->completedAt);
    }

    #[Test]
    public function test_failed_step_result(): void
    {
        $record = new SagaStepResult(
            id: 'step-result-3',
            instanceId: 'saga-1',
            stepName: 'charge',
            stepIndex: 0,
            direction: SagaStepDirection::Forward,
            status: SagaStepStatus::Failed,
            idempotencyKey: null,
            attempts: 3,
            resultData: null,
            errorMessage: 'Payment declined',
            startedAt: new DateTimeImmutable(),
            completedAt: new DateTimeImmutable(),
        );

        self::assertSame(SagaStepStatus::Failed, $record->status);
        self::assertSame(3, $record->attempts);
        self::assertSame('Payment declined', $record->errorMessage);
    }

    #[Test]
    public function test_compensation_step_result(): void
    {
        $record = new SagaStepResult(
            id: 'step-result-4',
            instanceId: 'saga-1',
            stepName: 'charge',
            stepIndex: 0,
            direction: SagaStepDirection::Compensating,
            status: SagaStepStatus::Completed,
            idempotencyKey: 'refund-ORD-1',
            attempts: 1,
            resultData: null,
            errorMessage: null,
            startedAt: new DateTimeImmutable(),
            completedAt: new DateTimeImmutable(),
        );

        self::assertSame(SagaStepDirection::Compensating, $record->direction);
        self::assertSame('refund-ORD-1', $record->idempotencyKey);
    }

    #[Test]
    public function test_skipped_irreversible_step(): void
    {
        $record = new SagaStepResult(
            id: 'step-result-5',
            instanceId: 'saga-1',
            stepName: 'send_email',
            stepIndex: 1,
            direction: SagaStepDirection::Compensating,
            status: SagaStepStatus::Skipped,
            idempotencyKey: null,
            attempts: 0,
            resultData: null,
            errorMessage: null,
            startedAt: new DateTimeImmutable(),
            completedAt: new DateTimeImmutable(),
        );

        self::assertSame(SagaStepStatus::Skipped, $record->status);
        self::assertSame(0, $record->attempts);
    }
}
