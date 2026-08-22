<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\SagaState;
use Pulsar\Saga\SagaStatus;
use Pulsar\Saga\Step\StepResult;

#[CoversClass(SagaState::class)]
final class SagaStateTest extends TestCase
{
    #[Test]
    public function test_initial_factory_creates_running_state(): void
    {
        $context = ['orderId' => 'ORDER-1', 'amount' => 5000];

        $state = SagaState::initial(
            sagaId: 'saga-123',
            definitionId: 'order_fulfillment',
            definitionVersion: 1,
            context: $context,
        );

        self::assertSame('saga-123', $state->sagaId);
        self::assertSame('order_fulfillment', $state->definitionId);
        self::assertSame(1, $state->definitionVersion);
        self::assertSame(0, $state->currentStepIndex);
        self::assertSame([], $state->stepResults);
        self::assertSame(SagaStatus::Running, $state->status);
        self::assertSame($context, $state->context);
        self::assertInstanceOf(DateTimeImmutable::class, $state->startedAt);
        self::assertNull($state->completedAt);
    }

    #[Test]
    public function test_withStepCompleted_increments_index_and_adds_result(): void
    {
        $state = SagaState::initial('saga-1', 'def', 1, []);
        $result = StepResult::success('step_1', ['key' => 'value']);

        $next = $state->withStepCompleted($result);

        self::assertSame(0, $state->currentStepIndex);
        self::assertSame([], $state->stepResults);

        self::assertSame(1, $next->currentStepIndex);
        self::assertCount(1, $next->stepResults);
        self::assertSame($result, $next->stepResults[0]);
    }

    #[Test]
    public function test_withStepCompleted_accumulates_results(): void
    {
        $state = SagaState::initial('saga-1', 'def', 1, []);
        $result1 = StepResult::success('step_1');
        $result2 = StepResult::success('step_2');

        $state = $state->withStepCompleted($result1);
        $state = $state->withStepCompleted($result2);

        self::assertSame(2, $state->currentStepIndex);
        self::assertCount(2, $state->stepResults);
        self::assertSame('step_1', $state->stepResults[0]->stepName);
        self::assertSame('step_2', $state->stepResults[1]->stepName);
    }

    #[Test]
    public function test_withContext_merges_additional_context(): void
    {
        $state = SagaState::initial('saga-1', 'def', 1, ['orderId' => 'ORDER-1']);

        $next = $state->withContext(['transactionId' => 'txn_123']);

        self::assertSame(['orderId' => 'ORDER-1'], $state->context);
        self::assertSame(['orderId' => 'ORDER-1', 'transactionId' => 'txn_123'], $next->context);
    }

    #[Test]
    public function test_withContext_overwrites_existing_keys(): void
    {
        $state = SagaState::initial('saga-1', 'def', 1, ['amount' => 100]);

        $next = $state->withContext(['amount' => 200]);

        self::assertSame(100, $state->context['amount']);
        self::assertSame(200, $next->context['amount']);
    }

    #[Test]
    public function test_withCompensating_transitions_status(): void
    {
        $state = SagaState::initial('saga-1', 'def', 1, []);

        $next = $state->withCompensating();

        self::assertSame(SagaStatus::Running, $state->status);
        self::assertSame(SagaStatus::Compensating, $next->status);
    }

    #[Test]
    public function test_withCompleted_transitions_status_and_sets_completedAt(): void
    {
        $state = SagaState::initial('saga-1', 'def', 1, []);

        $next = $state->withCompleted();

        self::assertSame(SagaStatus::Running, $state->status);
        self::assertNull($state->completedAt);

        self::assertSame(SagaStatus::Completed, $next->status);
        self::assertInstanceOf(DateTimeImmutable::class, $next->completedAt);
    }

    #[Test]
    public function test_withFailed_transitions_status_and_sets_completedAt(): void
    {
        $state = SagaState::initial('saga-1', 'def', 1, []);

        $next = $state->withFailed();

        self::assertSame(SagaStatus::Running, $state->status);
        self::assertNull($state->completedAt);

        self::assertSame(SagaStatus::Failed, $next->status);
        self::assertInstanceOf(DateTimeImmutable::class, $next->completedAt);
    }

    #[Test]
    public function test_immutability_state_transitions_do_not_modify_original(): void
    {
        $state = SagaState::initial('saga-1', 'def', 1, ['key' => 'value']);

        $withStep = $state->withStepCompleted(StepResult::success('step_1'));
        $withContext = $state->withContext(['new' => 'data']);
        $withComp = $state->withCompensating();
        $withCompleted = $state->withCompleted();
        $withFailed = $state->withFailed();

        self::assertSame(SagaStatus::Running, $state->status);
        self::assertSame(0, $state->currentStepIndex);
        self::assertSame([], $state->stepResults);
        self::assertSame(['key' => 'value'], $state->context);
        self::assertNull($state->completedAt);

        self::assertNotSame($state, $withStep);
        self::assertNotSame($state, $withContext);
        self::assertNotSame($state, $withComp);
        self::assertNotSame($state, $withCompleted);
        self::assertNotSame($state, $withFailed);
    }
}
