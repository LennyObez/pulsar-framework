<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use RuntimeException;

final class PaymentExceptionTest extends TestCase
{
    #[Test]
    public function invalidTransitionContainsDetails(): void
    {
        $e = PaymentException::invalidTransition('PaymentIntent', 'created', 'disputed');

        self::assertStringContainsString('PaymentIntent', $e->getMessage());
        self::assertStringContainsString('created', $e->getMessage());
        self::assertStringContainsString('disputed', $e->getMessage());
    }

    #[Test]
    public function notFoundContainsEntityAndId(): void
    {
        $e = PaymentException::notFound('Charge', 'ch_123');

        self::assertStringContainsString('Charge', $e->getMessage());
        self::assertStringContainsString('ch_123', $e->getMessage());
    }

    #[Test]
    public function invalidContainsMessage(): void
    {
        $e = PaymentException::invalid('custom error');

        self::assertSame('custom error', $e->getMessage());
    }

    #[Test]
    public function providerDeclinedHasErrorType(): void
    {
        $e = PaymentProviderException::declined('card_declined');

        self::assertStringContainsString('card_declined', $e->getMessage());
        self::assertSame('declined', $e->errorType);
    }

    #[Test]
    public function providerTimeoutHasErrorType(): void
    {
        $e = PaymentProviderException::timeout();

        self::assertSame('timeout', $e->errorType);
    }

    #[Test]
    public function providerNetworkErrorHasErrorType(): void
    {
        $e = PaymentProviderException::networkError();

        self::assertSame('network_error', $e->errorType);
    }

    #[Test]
    public function providerRateLimitedHasErrorType(): void
    {
        $e = PaymentProviderException::rateLimited();

        self::assertSame('rate_limited', $e->errorType);
    }

    #[Test]
    public function providerErrorPreservesPrevious(): void
    {
        $previous = new RuntimeException('inner');
        $e = PaymentProviderException::providerError('outer', $previous);

        self::assertSame('provider_error', $e->errorType);
        self::assertSame($previous, $e->getPrevious());
    }

    #[Test]
    public function refundFailedHasErrorType(): void
    {
        $e = PaymentProviderException::refundFailed('already_refunded');

        self::assertSame('refund_failed', $e->errorType);
        self::assertStringContainsString('already_refunded', $e->getMessage());
    }
}
