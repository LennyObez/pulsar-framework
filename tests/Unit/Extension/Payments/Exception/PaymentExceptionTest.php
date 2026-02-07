<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Exception\IdempotencyException;
use Pulsar\Extension\Payments\Exception\MoneyException;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Exception\WebhookException;

#[CoversClass(PaymentException::class)]
#[CoversClass(PaymentProviderException::class)]
#[CoversClass(IdempotencyException::class)]
#[CoversClass(WebhookException::class)]
#[CoversClass(MoneyException::class)]
final class PaymentExceptionTest extends TestCase
{
    #[Test]
    public function paymentExceptionInvalidTransition(): void
    {
        $e = PaymentException::invalidTransition('PaymentIntent', 'cancelled', 'captured');

        self::assertStringContainsString('Invalid PaymentIntent transition', $e->getMessage());
        self::assertStringContainsString('cancelled', $e->getMessage());
        self::assertStringContainsString('captured', $e->getMessage());
    }

    #[Test]
    public function paymentExceptionNotFound(): void
    {
        $e = PaymentException::notFound('Charge', 'ch_123');

        self::assertStringContainsString('not found', $e->getMessage());
        self::assertStringContainsString('ch_123', $e->getMessage());
    }

    #[Test]
    public function paymentProviderExceptionDeclined(): void
    {
        $e = PaymentProviderException::declined('insufficient_funds');

        self::assertStringContainsString('insufficient_funds', $e->getMessage());
        self::assertSame('declined', $e->errorType);
    }

    #[Test]
    public function paymentProviderExceptionTimeout(): void
    {
        $e = PaymentProviderException::timeout();

        self::assertSame('timeout', $e->errorType);
    }

    #[Test]
    public function paymentProviderExceptionNetworkError(): void
    {
        $e = PaymentProviderException::networkError();

        self::assertSame('network_error', $e->errorType);
    }

    #[Test]
    public function paymentProviderExceptionRateLimited(): void
    {
        $e = PaymentProviderException::rateLimited();

        self::assertSame('rate_limited', $e->errorType);
    }

    #[Test]
    public function idempotencyExceptionParameterMismatch(): void
    {
        $e = IdempotencyException::parameterMismatch('key-1');

        self::assertStringContainsString('different parameters', $e->getMessage());
    }

    #[Test]
    public function idempotencyExceptionConcurrentClaim(): void
    {
        $e = IdempotencyException::concurrentClaim('key-2');

        self::assertStringContainsString('currently being processed', $e->getMessage());
    }

    #[Test]
    public function idempotencyExceptionInvalidKey(): void
    {
        $e = IdempotencyException::invalidKey('too long');

        self::assertStringContainsString('too long', $e->getMessage());
    }

    #[Test]
    public function webhookExceptionInvalidSignature(): void
    {
        $e = WebhookException::invalidSignature();

        self::assertStringContainsString('signature', $e->getMessage());
    }

    #[Test]
    public function webhookExceptionExpiredTimestamp(): void
    {
        $e = WebhookException::expiredTimestamp(600, 300);

        self::assertStringContainsString('600', $e->getMessage());
        self::assertStringContainsString('300', $e->getMessage());
    }

    #[Test]
    public function webhookExceptionConcurrentClaim(): void
    {
        $e = WebhookException::concurrentClaim('evt_1');

        self::assertStringContainsString('currently being processed', $e->getMessage());
    }

    #[Test]
    public function moneyExceptionCurrencyMismatch(): void
    {
        $e = MoneyException::currencyMismatch(
            \Pulsar\Extension\Payments\Domain\Currency::USD,
            \Pulsar\Extension\Payments\Domain\Currency::EUR,
        );

        self::assertStringContainsString('USD', $e->getMessage());
        self::assertStringContainsString('EUR', $e->getMessage());
    }

    #[Test]
    public function moneyExceptionNegativeAmount(): void
    {
        $e = MoneyException::negativeAmount(-5);

        self::assertStringContainsString('-5', $e->getMessage());
    }
}
