<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Exception\SagaException;
use Pulsar\Saga\Internal\StepBuilder;
use Pulsar\Saga\SagaDefinition;
use Pulsar\Saga\SagaDefinitionBuilder;
use Pulsar\Saga\Step\BackoffStrategy;
use stdClass;

#[CoversClass(SagaDefinitionBuilder::class)]
#[CoversClass(StepBuilder::class)]
final class SagaDefinitionBuilderTest extends TestCase
{
    #[Test]
    public function test_minimal_saga_with_one_step(): void
    {
        $definition = SagaDefinitionBuilder::create('simple')
            ->step('step_one')
                ->forward(stdClass::class)
            ->build();

        self::assertInstanceOf(SagaDefinition::class, $definition);
        self::assertSame('simple', $definition->name);
        self::assertSame(1, $definition->stepCount());
        self::assertSame(['step_one'], $definition->getStepNames());
    }

    #[Test]
    public function test_multi_step_saga(): void
    {
        $definition = SagaDefinitionBuilder::create('order_fulfillment')
            ->step('charge_payment')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->step('reserve_inventory')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
            ->step('ship_order')
                ->forward(stdClass::class)
            ->build();

        self::assertSame(3, $definition->stepCount());
        self::assertSame(['charge_payment', 'reserve_inventory', 'ship_order'], $definition->getStepNames());
    }

    #[Test]
    public function test_step_with_forward_idempotency_key(): void
    {
        $keyGen = static function (array $ctx): string {
            /** @var string $orderId */
            $orderId = $ctx['orderId'];

            return 'charge-' . $orderId;
        };

        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
                ->forwardIdempotencyKey($keyGen)
            ->build();

        $step = $definition->getStep('charge');

        self::assertNotNull($step->forwardIdempotencyKey);
        self::assertSame('charge-ORD-1', ($step->forwardIdempotencyKey)(['orderId' => 'ORD-1']));
    }

    #[Test]
    public function test_step_with_compensation_idempotency_key(): void
    {
        $keyGen = static function (array $ctx): string {
            /** @var string $orderId */
            $orderId = $ctx['orderId'];

            return 'refund-' . $orderId;
        };

        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
                ->compensationIdempotencyKey($keyGen)
            ->build();

        $step = $definition->getStep('charge');

        self::assertNotNull($step->compensationIdempotencyKey);
        self::assertSame('refund-ORD-1', ($step->compensationIdempotencyKey)(['orderId' => 'ORD-1']));
    }

    #[Test]
    public function test_step_with_retry_policy(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
                ->retryPolicy(maxAttempts: 5, backoff: BackoffStrategy::Linear, initialDelayMs: 200)
            ->build();

        $step = $definition->getStep('charge');

        self::assertSame(5, $step->retryPolicy->maxAttempts);
        self::assertSame(BackoffStrategy::Linear, $step->retryPolicy->backoff);
        self::assertSame(200, $step->retryPolicy->initialDelayMs);
    }

    #[Test]
    public function test_step_with_compensation_retry_policy(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
                ->compensate(stdClass::class)
                ->compensationRetryPolicy(maxAttempts: 10, backoff: BackoffStrategy::Exponential, initialDelayMs: 50)
            ->build();

        $step = $definition->getStep('charge');

        self::assertSame(10, $step->compensationRetryPolicy->maxAttempts);
        self::assertSame(BackoffStrategy::Exponential, $step->compensationRetryPolicy->backoff);
        self::assertSame(50, $step->compensationRetryPolicy->initialDelayMs);
    }

    #[Test]
    public function test_step_marked_irreversible(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('send_email')
                ->forward(stdClass::class)
                ->irreversible()
            ->build();

        $step = $definition->getStep('send_email');

        self::assertTrue($step->irreversible);
        self::assertFalse($step->hasCompensation());
    }

    #[Test]
    public function test_metadata(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->metadata(['domain' => 'payments', 'priority' => 'high'])
            ->step('charge')
                ->forward(stdClass::class)
            ->build();

        self::assertSame(['domain' => 'payments', 'priority' => 'high'], $definition->metadata);
    }

    #[Test]
    public function test_build_no_steps_throws(): void
    {
        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('has no steps defined');

        (void) SagaDefinitionBuilder::create('empty')->build();
    }

    #[Test]
    public function test_forward_without_step_throws(): void
    {
        $this->expectException(SagaException::class);

        SagaDefinitionBuilder::create('test')
            ->forward(stdClass::class);
    }

    #[Test]
    public function test_compensate_without_step_throws(): void
    {
        $this->expectException(SagaException::class);

        SagaDefinitionBuilder::create('test')
            ->compensate(stdClass::class);
    }

    #[Test]
    public function test_step_preserves_order(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('c')
                ->forward(stdClass::class)
            ->step('a')
                ->forward(stdClass::class)
            ->step('b')
                ->forward(stdClass::class)
            ->build();

        self::assertSame(['c', 'a', 'b'], $definition->getStepNames());
    }

    #[Test]
    public function test_fully_configured_step(): void
    {
        $forwardKey = static function (array $ctx): string {
            /** @var string $id */
            $id = $ctx['id'];

            return 'fwd-' . $id;
        };
        $compKey = static function (array $ctx): string {
            /** @var string $id */
            $id = $ctx['id'];

            return 'comp-' . $id;
        };

        $definition = SagaDefinitionBuilder::create('full_test')
            ->step('process')
                ->forward(stdClass::class)
                ->forwardIdempotencyKey($forwardKey)
                ->compensate(stdClass::class)
                ->compensationIdempotencyKey($compKey)
                ->retryPolicy(maxAttempts: 3, backoff: BackoffStrategy::Exponential, initialDelayMs: 100)
                ->compensationRetryPolicy(maxAttempts: 5, backoff: BackoffStrategy::Linear, initialDelayMs: 50)
            ->build();

        $step = $definition->getStep('process');

        self::assertSame(stdClass::class, $step->forwardAction);
        self::assertNotNull($step->forwardIdempotencyKey);
        self::assertSame(stdClass::class, $step->compensationAction);
        self::assertNotNull($step->compensationIdempotencyKey);
        self::assertSame(3, $step->retryPolicy->maxAttempts);
        self::assertSame(5, $step->compensationRetryPolicy->maxAttempts);
        self::assertFalse($step->irreversible);
    }

    #[Test]
    public function test_default_retry_policy_when_not_specified(): void
    {
        $definition = SagaDefinitionBuilder::create('test')
            ->step('charge')
                ->forward(stdClass::class)
            ->build();

        $step = $definition->getStep('charge');

        self::assertSame(1, $step->retryPolicy->maxAttempts);
        self::assertSame(BackoffStrategy::Exponential, $step->retryPolicy->backoff);
        self::assertSame(100, $step->retryPolicy->initialDelayMs);
    }
}
