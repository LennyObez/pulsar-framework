<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Exception\SagaException;
use RuntimeException;

#[CoversClass(SagaException::class)]
final class SagaExceptionTest extends TestCase
{
    #[Test]
    public function test_stepFailed(): void
    {
        $exception = SagaException::stepFailed('saga-1', 'charge', 'Payment declined');

        self::assertInstanceOf(SagaException::class, $exception);
        self::assertStringContainsString('saga-1', $exception->getMessage());
        self::assertStringContainsString('charge', $exception->getMessage());
        self::assertStringContainsString('Payment declined', $exception->getMessage());
    }

    #[Test]
    public function test_sagaNotFound(): void
    {
        $exception = SagaException::sagaNotFound('saga-missing');

        self::assertInstanceOf(SagaException::class, $exception);
        self::assertStringContainsString('saga-missing', $exception->getMessage());
        self::assertStringContainsString('not found', $exception->getMessage());
    }

    #[Test]
    public function test_invalidState(): void
    {
        $exception = SagaException::invalidState('saga-1', 'running', 'completed');

        self::assertInstanceOf(SagaException::class, $exception);
        self::assertStringContainsString('saga-1', $exception->getMessage());
        self::assertStringContainsString('completed', $exception->getMessage());
        self::assertStringContainsString('running', $exception->getMessage());
    }

    #[Test]
    public function test_definitionNotFound(): void
    {
        $exception = SagaException::definitionNotFound('order_fulfillment');

        self::assertInstanceOf(SagaException::class, $exception);
        self::assertStringContainsString('order_fulfillment', $exception->getMessage());
        self::assertStringContainsString('not found', $exception->getMessage());
    }

    #[Test]
    public function test_noStepsDefined(): void
    {
        $exception = SagaException::noStepsDefined('empty_saga');

        self::assertInstanceOf(SagaException::class, $exception);
        self::assertStringContainsString('empty_saga', $exception->getMessage());
        self::assertStringContainsString('no steps defined', $exception->getMessage());
    }

    #[Test]
    public function test_extends_RuntimeException(): void
    {
        $exception = SagaException::sagaNotFound('test');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }
}
