<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Gateway;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\BancontactConfig;
use Pulsar\Extension\Payments\Config\StripeConfig;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Gateway\BancontactGateway;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;

final class BancontactGatewayTest extends TestCase
{
    private BancontactGateway $gateway;

    protected function setUp(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-16T12:00:00+00:00'));
        $bancontactConfig = BancontactConfig::fromArray(['enabled' => true, 'preferred_language' => 'fr']);
        $stripeConfig = StripeConfig::fromArray(['secret_key' => 'sk_test_123']);

        $this->gateway = new BancontactGateway($bancontactConfig, $stripeConfig, $clock);
    }

    #[Test]
    public function nameReturnsBancontact(): void
    {
        self::assertSame('bancontact', $this->gateway->name());
    }

    #[Test]
    public function createIntentReturnsValidIntentForEur(): void
    {
        $amount = Money::of(5000, Currency::EUR);
        $intent = $this->gateway->createIntent($amount, 'idem_key');

        self::assertStringStartsWith('bct_', $intent->id);
        self::assertSame(5000, $intent->amount->amount);
        self::assertSame(Currency::EUR, $intent->amount->currency);
        self::assertSame(PaymentIntentStatus::Created, $intent->status);
        self::assertSame('bancontact', $intent->provider);
        self::assertSame('bancontact', $intent->metadata['payment_method_type']);
        self::assertSame('fr', $intent->metadata['preferred_language']);
    }

    #[Test]
    public function createIntentRejectsNonEurCurrency(): void
    {
        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageIsOrContains('only supports EUR');

        $this->gateway->createIntent(Money::of(1000, Currency::GBP), 'key');
    }

    #[Test]
    public function createIntentFailsWithoutStripeKey(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-16T12:00:00+00:00'));
        $gateway = new BancontactGateway(
            BancontactConfig::fromArray([]),
            StripeConfig::fromArray([]),
            $clock,
        );

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageIsOrContains('Stripe secret key');

        $gateway->createIntent(Money::of(1000, Currency::EUR), 'key');
    }

    #[Test]
    public function captureIntentReturnsPendingCharge(): void
    {
        $charge = $this->gateway->captureIntent('bct_intent_1', 'key');

        self::assertStringStartsWith('bct_ch_', $charge->id);
        self::assertSame(ChargeStatus::Pending, $charge->status);
        self::assertSame('bancontact', $charge->provider);
    }

    #[Test]
    public function cancelIntentReturnsCancelledStatus(): void
    {
        $intent = $this->gateway->cancelIntent('bct_intent_1', 'key');

        self::assertSame(PaymentIntentStatus::Cancelled, $intent->status);
    }

    #[Test]
    public function refundReturnsPendingRefund(): void
    {
        $refund = $this->gateway->refund('bct_ch_1', Money::of(2500, Currency::EUR), 'key');

        self::assertStringStartsWith('bct_rf_', $refund->id);
        self::assertSame(RefundStatus::Pending, $refund->status);
    }

    #[Test]
    public function getIntentThrowsNotFound(): void
    {
        $this->expectException(PaymentException::class);
        $this->gateway->getIntent('nonexistent');
    }

    #[Test]
    public function preferredLanguageFallsBackToNlForInvalidValues(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-16T12:00:00+00:00'));
        $gateway = new BancontactGateway(
            BancontactConfig::fromArray(['preferred_language' => 'invalid']),
            StripeConfig::fromArray(['secret_key' => 'sk_test']),
            $clock,
        );

        $intent = $gateway->createIntent(Money::of(1000, Currency::EUR), 'key');

        self::assertSame('nl', $intent->metadata['preferred_language']);
    }
}
