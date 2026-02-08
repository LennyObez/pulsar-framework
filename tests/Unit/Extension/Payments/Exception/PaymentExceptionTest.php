<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Exception\MoneyException;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Idempotency\Exception\IdempotencyException;
use Pulsar\Webhook\Exception\WebhookException;
use RuntimeException;

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
            Currency::USD,
            Currency::EUR,
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

    #[Test]
    public function moneyExceptionInvalidParts(): void
    {
        $e = MoneyException::invalidParts(0);

        self::assertStringContainsString('0', $e->getMessage());
        self::assertStringContainsString('positive', $e->getMessage());
    }

    #[Test]
    public function moneyExceptionNegativeMultiplier(): void
    {
        $e = MoneyException::negativeMultiplier(-2);

        self::assertStringContainsString('-2', $e->getMessage());
    }

    #[Test]
    public function moneyExceptionInvalidBasisPoints(): void
    {
        $e = MoneyException::invalidBasisPoints(-100);

        self::assertStringContainsString('-100', $e->getMessage());
    }

    #[Test]
    public function paymentProviderExceptionProviderError(): void
    {
        $previous = new RuntimeException('original');
        $e = PaymentProviderException::providerError('something broke', $previous);

        self::assertSame('provider_error', $e->errorType);
        self::assertStringContainsString('something broke', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }

    #[Test]
    public function paymentProviderExceptionRefundFailed(): void
    {
        $e = PaymentProviderException::refundFailed('card_not_found');

        self::assertSame('refund_failed', $e->errorType);
        self::assertStringContainsString('card_not_found', $e->getMessage());
    }

    #[Test]
    public function idempotencyExceptionCommitFailed(): void
    {
        $e = IdempotencyException::commitFailed('key-x');

        self::assertStringContainsString('key-x', $e->getMessage());
        self::assertStringContainsString('commit', strtolower($e->getMessage()));
    }

    #[Test]
    public function webhookExceptionHandlerFailed(): void
    {
        $e = WebhookException::handlerFailed('evt_42', 'db timeout');

        self::assertStringContainsString('evt_42', $e->getMessage());
        self::assertStringContainsString('db timeout', $e->getMessage());
    }

    #[Test]
    public function webhookExceptionMalformedHeader(): void
    {
        $e = WebhookException::malformedHeader('missing t= field');

        self::assertStringContainsString('missing t= field', $e->getMessage());
    }
}
