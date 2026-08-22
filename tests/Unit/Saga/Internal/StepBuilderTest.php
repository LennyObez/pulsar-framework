<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Internal\StepBuilder;
use Pulsar\Saga\Step\RetryPolicy;
use ReflectionProperty;
use stdClass;

use function is_scalar;

#[CoversClass(StepBuilder::class)]
final class StepBuilderTest extends TestCase
{
    #[Test]
    public function constructsWithNameAndDefaults(): void
    {
        $builder = new StepBuilder('charge-payment');

        self::assertSame('charge-payment', $builder->name);
        self::assertSame('', $builder->forwardAction);
        self::assertNull($builder->forwardIdempotencyKey);
        self::assertNull($builder->compensationAction);
        self::assertNull($builder->compensationIdempotencyKey);
        self::assertInstanceOf(RetryPolicy::class, $builder->retryPolicy);
        self::assertInstanceOf(RetryPolicy::class, $builder->compensationRetryPolicy);
        self::assertFalse($builder->irreversible);
    }

    #[Test]
    public function forwardActionCanBeSet(): void
    {
        $builder = new StepBuilder('reserve-inventory');
        $builder->forwardAction = 'App\\Saga\\Action\\ReserveInventory';

        self::assertSame('App\\Saga\\Action\\ReserveInventory', $builder->forwardAction);
    }

    #[Test]
    public function compensationActionCanBeSet(): void
    {
        $builder = new StepBuilder('reserve-inventory');
        $class = stdClass::class;
        $builder->compensationAction = $class;

        self::assertSame($class, $builder->compensationAction);
    }

    #[Test]
    public function forwardIdempotencyKeyAcceptsClosure(): void
    {
        $builder = new StepBuilder('charge-payment');
        $keyClosure = static fn(array $ctx): string => 'key-' . (is_scalar($ctx['order_id'] ?? null) ? (string) $ctx['order_id'] : 'none');
        $builder->forwardIdempotencyKey = $keyClosure;

        self::assertNotNull($builder->forwardIdempotencyKey);

        $key = ($builder->forwardIdempotencyKey)(['order_id' => '42']);
        self::assertSame('key-42', $key);
    }

    #[Test]
    public function compensationIdempotencyKeyAcceptsClosure(): void
    {
        $builder = new StepBuilder('charge-payment');
        $compClosure = static fn(array $ctx): string => 'comp-' . (is_scalar($ctx['order_id'] ?? null) ? (string) $ctx['order_id'] : '');
        $builder->compensationIdempotencyKey = $compClosure;

        self::assertNotNull($builder->compensationIdempotencyKey);

        $key = ($builder->compensationIdempotencyKey)(['order_id' => '99']);
        self::assertSame('comp-99', $key);
    }

    #[Test]
    public function irreversibleCanBeSetToTrue(): void
    {
        $builder = new StepBuilder('send-notification');
        $builder->irreversible = true;

        self::assertTrue($builder->irreversible);
    }

    #[Test]
    public function retryPolicyCanBeReplaced(): void
    {
        $builder = new StepBuilder('charge-payment');
        $custom = new RetryPolicy(maxAttempts: 5);
        $builder->retryPolicy = $custom;

        self::assertSame($custom, $builder->retryPolicy);
    }

    #[Test]
    public function compensationRetryPolicyCanBeReplaced(): void
    {
        $builder = new StepBuilder('charge-payment');
        $custom = new RetryPolicy(maxAttempts: 10);
        $builder->compensationRetryPolicy = $custom;

        self::assertSame($custom, $builder->compensationRetryPolicy);
    }

    #[Test]
    public function nameIsReadonly(): void
    {
        $builder = new StepBuilder('immutable-name');

        $reflection = new ReflectionProperty($builder, 'name');
        self::assertTrue($reflection->isReadOnly());
        self::assertSame('immutable-name', $builder->name);
    }

    #[Test]
    public function defaultRetryPoliciesAreDistinctInstances(): void
    {
        $builder = new StepBuilder('test');

        self::assertNotSame($builder->retryPolicy, $builder->compensationRetryPolicy);
    }
}
