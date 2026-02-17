<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Gateway;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\IdealConfig;
use Pulsar\Extension\Payments\Config\StripeConfig;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Gateway\IdealGateway;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;

final class IdealGatewayTest extends TestCase
{
    private IdealGateway $gateway;

    protected function setUp(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-16T12:00:00+00:00'));
        $idealConfig = IdealConfig::fromArray(['enabled' => true, 'provider' => 'stripe']);
        $stripeConfig = StripeConfig::fromArray(['secret_key' => 'sk_test_123']);

        $this->gateway = new IdealGateway($idealConfig, $stripeConfig, $clock);
    }

    #[Test]
    public function nameReturnsIdeal(): void
    {
        self::assertSame('ideal', $this->gateway->name());
    }

    #[Test]
    public function createIntentReturnsValidIntentForEur(): void
    {
        $amount = Money::of(3500, Currency::EUR);
        $intent = $this->gateway->createIntent($amount, 'idem_key');

        self::assertStringStartsWith('idl_', $intent->id);
        self::assertSame(3500, $intent->amount->amount);
        self::assertSame(Currency::EUR, $intent->amount->currency);
        self::assertSame(PaymentIntentStatus::Created, $intent->status);
        self::assertSame('ideal', $intent->provider);
        self::assertSame('ideal', $intent->metadata['payment_method_type']);
        self::assertSame('stripe', $intent->metadata['upstream_provider']);
    }

    #[Test]
    public function createIntentRejectsNonEurCurrency(): void
    {
        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessage('only supports EUR');

        $this->gateway->createIntent(Money::of(1000, Currency::USD), 'key');
    }

    #[Test]
    public function createIntentFailsWithoutStripeKeyWhenUsingStripe(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-16T12:00:00+00:00'));
        $gateway = new IdealGateway(
            IdealConfig::fromArray(['provider' => 'stripe']),
            StripeConfig::fromArray([]),
            $clock,
        );

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessage('Stripe secret key');

        $gateway->createIntent(Money::of(1000, Currency::EUR), 'key');
    }

    #[Test]
    public function captureIntentReturnsSucceededCharge(): void
    {
        $charge = $this->gateway->captureIntent('idl_intent_1', 'key');

        self::assertStringStartsWith('idl_ch_', $charge->id);
        self::assertSame(ChargeStatus::Succeeded, $charge->status);
        self::assertSame('ideal', $charge->provider);
    }

    #[Test]
    public function cancelIntentReturnsCancelledStatus(): void
    {
        $intent = $this->gateway->cancelIntent('idl_intent_1', 'key');

        self::assertSame(PaymentIntentStatus::Cancelled, $intent->status);
    }

    #[Test]
    public function refundReturnsPendingRefund(): void
    {
        $refund = $this->gateway->refund('idl_ch_1', null, 'key');

        self::assertStringStartsWith('idl_rf_', $refund->id);
        self::assertSame(RefundStatus::Pending, $refund->status);
    }

    #[Test]
    public function getIntentThrowsNotFound(): void
    {
        $this->expectException(PaymentException::class);
        $this->gateway->getIntent('nonexistent');
    }

    #[Test]
    public function createIntentWithMollieProviderDoesNotRequireStripeKey(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-16T12:00:00+00:00'));
        $gateway = new IdealGateway(
            IdealConfig::fromArray(['provider' => 'mollie']),
            StripeConfig::fromArray([]),
            $clock,
        );

        $intent = $gateway->createIntent(Money::of(1000, Currency::EUR), 'key');

        self::assertSame('mollie', $intent->metadata['upstream_provider']);
    }
}
