<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Step;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Step\BackoffStrategy;
use Pulsar\Saga\Step\RetryPolicy;
use Pulsar\Saga\Step\SagaStep;
use stdClass;

#[CoversClass(SagaStep::class)]
final class SagaStepTest extends TestCase
{
    #[Test]
    public function test_minimal_step_construction(): void
    {
        $step = new SagaStep(
            name: 'charge_payment',
            forwardAction: stdClass::class,
        );

        self::assertSame('charge_payment', $step->name);
        self::assertSame(stdClass::class, $step->forwardAction);
        self::assertNull($step->forwardIdempotencyKey);
        self::assertNull($step->compensationAction);
        self::assertNull($step->compensationIdempotencyKey);
        self::assertFalse($step->irreversible);
    }

    #[Test]
    public function test_full_step_construction(): void
    {
        $forwardKey = static function (array $ctx): string {
            /** @var string $orderId */
            $orderId = $ctx['orderId'];

            return 'charge-' . $orderId;
        };
        $compKey = static function (array $ctx): string {
            /** @var string $orderId */
            $orderId = $ctx['orderId'];

            return 'refund-' . $orderId;
        };
        $retryPolicy = new RetryPolicy(maxAttempts: 3, backoff: BackoffStrategy::Exponential, initialDelayMs: 200);
        $compRetryPolicy = new RetryPolicy(maxAttempts: 5, backoff: BackoffStrategy::Linear, initialDelayMs: 100);

        $step = new SagaStep(
            name: 'charge_payment',
            forwardAction: stdClass::class,
            forwardIdempotencyKey: $forwardKey,
            compensationAction: stdClass::class,
            compensationIdempotencyKey: $compKey,
            retryPolicy: $retryPolicy,
            compensationRetryPolicy: $compRetryPolicy,
            irreversible: false,
        );

        self::assertSame('charge_payment', $step->name);
        self::assertSame(stdClass::class, $step->forwardAction);
        self::assertSame($forwardKey, $step->forwardIdempotencyKey);
        self::assertSame(stdClass::class, $step->compensationAction);
        self::assertSame($compKey, $step->compensationIdempotencyKey);
        self::assertSame($retryPolicy, $step->retryPolicy);
        self::assertSame($compRetryPolicy, $step->compensationRetryPolicy);
        self::assertFalse($step->irreversible);
    }

    #[Test]
    public function test_hasCompensation_true_when_action_set_and_not_irreversible(): void
    {
        $step = new SagaStep(
            name: 'charge_payment',
            forwardAction: stdClass::class,
            compensationAction: stdClass::class,
        );

        self::assertTrue($step->hasCompensation());
    }

    #[Test]
    public function test_hasCompensation_false_when_no_compensation_action(): void
    {
        $step = new SagaStep(
            name: 'send_email',
            forwardAction: stdClass::class,
        );

        self::assertFalse($step->hasCompensation());
    }

    #[Test]
    public function test_hasCompensation_false_when_irreversible(): void
    {
        $step = new SagaStep(
            name: 'send_email',
            forwardAction: stdClass::class,
            compensationAction: stdClass::class,
            irreversible: true,
        );

        self::assertFalse($step->hasCompensation());
    }

    #[Test]
    public function test_idempotency_key_generators_callable(): void
    {
        $forwardKey = static function (array $ctx): string {
            /** @var string $orderId */
            $orderId = $ctx['orderId'];

            return 'charge-' . $orderId;
        };
        $compKey = static function (array $ctx): string {
            /** @var string $orderId */
            $orderId = $ctx['orderId'];

            return 'refund-' . $orderId;
        };

        $step = new SagaStep(
            name: 'charge_payment',
            forwardAction: stdClass::class,
            forwardIdempotencyKey: $forwardKey,
            compensationIdempotencyKey: $compKey,
        );

        self::assertNotNull($step->forwardIdempotencyKey);
        self::assertNotNull($step->compensationIdempotencyKey);
        self::assertSame('charge-ORDER-1', ($step->forwardIdempotencyKey)(['orderId' => 'ORDER-1']));
        self::assertSame('refund-ORDER-1', ($step->compensationIdempotencyKey)(['orderId' => 'ORDER-1']));
    }

    #[Test]
    public function test_default_retry_policies(): void
    {
        $step = new SagaStep(
            name: 'charge_payment',
            forwardAction: stdClass::class,
        );

        self::assertSame(1, $step->retryPolicy->maxAttempts);
        self::assertSame(1, $step->compensationRetryPolicy->maxAttempts);
    }
}
