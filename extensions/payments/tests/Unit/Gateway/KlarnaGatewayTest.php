<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Gateway;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\KlarnaConfig;
use Pulsar\Extension\Payments\Config\StripeConfig;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Gateway\KlarnaGateway;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;

final class KlarnaGatewayTest extends TestCase
{
    private KlarnaGateway $gateway;

    protected function setUp(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-16T12:00:00+00:00'));
        $klarnaConfig = KlarnaConfig::fromArray([
            'enabled' => true,
            'region' => 'eu',
            'pay_later_enabled' => true,
            'pay_now_enabled' => true,
            'slice_it_enabled' => true,
        ]);
        $stripeConfig = StripeConfig::fromArray(['secret_key' => 'sk_test_123']);

        $this->gateway = new KlarnaGateway($klarnaConfig, $stripeConfig, $clock);
    }

    #[Test]
    public function nameReturnsKlarna(): void
    {
        self::assertSame('klarna', $this->gateway->name());
    }

    #[Test]
    #[DataProvider('supportedCurrencyProvider')]
    public function createIntentAcceptsSupportedCurrencies(Currency $currency): void
    {
        $intent = $this->gateway->createIntent(Money::of(5000, $currency), 'key');

        self::assertStringStartsWith('kla_', $intent->id);
        self::assertSame($currency, $intent->amount->currency);
        self::assertSame(PaymentIntentStatus::Created, $intent->status);
        self::assertSame('klarna', $intent->provider);
    }

    /**
     * @return iterable<string, array{Currency}>
     */
    public static function supportedCurrencyProvider(): iterable
    {
        yield 'EUR' => [Currency::EUR];
        yield 'SEK' => [Currency::SEK];
        yield 'NOK' => [Currency::NOK];
        yield 'DKK' => [Currency::DKK];
        yield 'GBP' => [Currency::GBP];
        yield 'USD' => [Currency::USD];
        yield 'CHF' => [Currency::CHF];
        yield 'PLN' => [Currency::PLN];
        yield 'CZK' => [Currency::CZK];
    }

    #[Test]
    public function createIntentRejectsUnsupportedCurrency(): void
    {
        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessage('does not support currency: JPY');

        $this->gateway->createIntent(Money::of(10000, Currency::JPY), 'key');
    }

    #[Test]
    public function createIntentDefaultsToPayLaterCategory(): void
    {
        $intent = $this->gateway->createIntent(Money::of(5000, Currency::EUR), 'key');

        self::assertSame('pay_later', $intent->metadata['klarna_category']);
        self::assertSame('eu', $intent->metadata['klarna_region']);
    }

    #[Test]
    public function createIntentRespectsRequestedKlarnaCategory(): void
    {
        $intent = $this->gateway->createIntent(
            Money::of(5000, Currency::EUR),
            'key',
            ['klarna_category' => 'slice_it'],
        );

        self::assertSame('slice_it', $intent->metadata['klarna_category']);
    }

    #[Test]
    public function createIntentFallsBackWhenRequestedCategoryDisabled(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-16T12:00:00+00:00'));
        $config = KlarnaConfig::fromArray([
            'pay_later_enabled' => false,
            'pay_now_enabled' => true,
            'slice_it_enabled' => false,
        ]);
        $gateway = new KlarnaGateway($config, StripeConfig::fromArray(['secret_key' => 'sk']), $clock);

        $intent = $gateway->createIntent(
            Money::of(5000, Currency::EUR),
            'key',
            ['klarna_category' => 'pay_later'],
        );

        self::assertSame('pay_now', $intent->metadata['klarna_category']);
    }

    #[Test]
    public function createIntentFailsWithoutStripeKey(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-16T12:00:00+00:00'));
        $gateway = new KlarnaGateway(
            KlarnaConfig::fromArray([]),
            StripeConfig::fromArray([]),
            $clock,
        );

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessage('Stripe secret key');

        $gateway->createIntent(Money::of(1000, Currency::EUR), 'key');
    }

    #[Test]
    public function captureIntentReturnsPendingCharge(): void
    {
        $charge = $this->gateway->captureIntent('kla_intent_1', 'key');

        self::assertStringStartsWith('kla_ch_', $charge->id);
        self::assertSame(ChargeStatus::Pending, $charge->status);
        self::assertSame('klarna', $charge->provider);
    }

    #[Test]
    public function cancelIntentReturnsCancelledStatus(): void
    {
        $intent = $this->gateway->cancelIntent('kla_intent_1', 'key');

        self::assertSame(PaymentIntentStatus::Cancelled, $intent->status);
    }

    #[Test]
    public function refundReturnsPendingRefund(): void
    {
        $refund = $this->gateway->refund('kla_ch_1', Money::of(2500, Currency::EUR), 'key');

        self::assertStringStartsWith('kla_rf_', $refund->id);
        self::assertSame(RefundStatus::Pending, $refund->status);
    }

    #[Test]
    public function getIntentThrowsNotFound(): void
    {
        $this->expectException(PaymentException::class);
        $this->gateway->getIntent('nonexistent');
    }

    #[Test]
    public function defaultCategoryWhenPayLaterDisabled(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-03-16T12:00:00+00:00'));
        $config = KlarnaConfig::fromArray([
            'pay_later_enabled' => false,
            'pay_now_enabled' => false,
            'slice_it_enabled' => true,
        ]);
        $gateway = new KlarnaGateway($config, StripeConfig::fromArray(['secret_key' => 'sk']), $clock);

        $intent = $gateway->createIntent(Money::of(5000, Currency::EUR), 'key');

        self::assertSame('slice_it', $intent->metadata['klarna_category']);
    }
}
