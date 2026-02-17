<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use RuntimeException;

final class PaymentProviderExceptionTest extends TestCase
{
    #[Test]
    public function declinedSetsErrorType(): void
    {
        $e = PaymentProviderException::declined('Insufficient funds');

        self::assertStringContainsString('Insufficient funds', $e->getMessage());
        self::assertSame('declined', $e->errorType);
    }

    #[Test]
    public function timeoutSetsErrorType(): void
    {
        $e = PaymentProviderException::timeout();

        self::assertStringContainsString('timed out', $e->getMessage());
        self::assertSame('timeout', $e->errorType);
    }

    #[Test]
    public function timeoutAcceptsCustomMessage(): void
    {
        $e = PaymentProviderException::timeout('Custom timeout');

        self::assertSame('Custom timeout', $e->getMessage());
    }

    #[Test]
    public function networkErrorSetsErrorType(): void
    {
        $e = PaymentProviderException::networkError();

        self::assertSame('network_error', $e->errorType);
    }

    #[Test]
    public function rateLimitedSetsErrorType(): void
    {
        $e = PaymentProviderException::rateLimited();

        self::assertSame('rate_limited', $e->errorType);
    }

    #[Test]
    public function providerErrorPreservesPrevious(): void
    {
        $previous = new RuntimeException('upstream');
        $e = PaymentProviderException::providerError('Provider down', $previous);

        self::assertSame('provider_error', $e->errorType);
        self::assertSame($previous, $e->getPrevious());
    }

    #[Test]
    public function refundFailedSetsErrorType(): void
    {
        $e = PaymentProviderException::refundFailed('Already refunded');

        self::assertStringContainsString('Already refunded', $e->getMessage());
        self::assertSame('refund_failed', $e->errorType);
    }
}
