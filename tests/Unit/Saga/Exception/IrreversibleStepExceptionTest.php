<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Exception\IrreversibleStepException;
use Pulsar\Saga\Exception\SagaException;

#[CoversClass(IrreversibleStepException::class)]
final class IrreversibleStepExceptionTest extends TestCase
{
    #[Test]
    public function test_cannotCompensate(): void
    {
        $exception = IrreversibleStepException::cannotCompensate('saga-1', 'send_email');

        self::assertInstanceOf(IrreversibleStepException::class, $exception);
        self::assertStringContainsString('send_email', $exception->getMessage());
        self::assertStringContainsString('saga-1', $exception->getMessage());
        self::assertStringContainsString('irreversible', $exception->getMessage());
    }

    #[Test]
    public function test_extends_SagaException(): void
    {
        $exception = IrreversibleStepException::cannotCompensate('saga-1', 'step');

        self::assertInstanceOf(SagaException::class, $exception);
    }
}
