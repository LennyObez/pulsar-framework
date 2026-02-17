<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Gateway;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\PayconiqConfig;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Gateway\PayconiqGateway;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;

final class PayconiqGatewayTest extends TestCase
{
    private PayconiqGateway $gateway;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2026-03-16T12:00:00+00:00'));
        $config = PayconiqConfig::fromArray([
            'merchant_id' => 'merch_test',
            'api_key' => 'key_test',
            'environment' => 'ext',
            'enabled' => true,
            'callback_url' => 'https://example.com/callback',
        ]);

        $this->gateway = new PayconiqGateway($config, $this->clock);
    }

    #[Test]
    public function nameReturnsPayconiq(): void
    {
        self::assertSame('payconiq', $this->gateway->name());
    }

    #[Test]
    public function createIntentReturnsValidIntentForEur(): void
    {
        $amount = Money::of(2500, Currency::EUR);
        $intent = $this->gateway->createIntent($amount, 'idem_key_1');

        self::assertStringStartsWith('pcq_', $intent->id);
        self::assertSame(2500, $intent->amount->amount);
        self::assertSame(Currency::EUR, $intent->amount->currency);
        self::assertSame(PaymentIntentStatus::Created, $intent->status);
        self::assertSame('payconiq', $intent->provider);
        self::assertSame('idem_key_1', $intent->idempotencyKey);
        self::assertSame('merch_test', $intent->metadata['merchant_id']);
        self::assertArrayHasKey('qr_url', $intent->metadata);
    }

    #[Test]
    public function createIntentRejectsNonEurCurrency(): void
    {
        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessage('Payconiq only supports EUR');

        $this->gateway->createIntent(Money::of(1000, Currency::USD), 'key');
    }

    #[Test]
    public function createIntentFailsWithoutMerchantId(): void
    {
        $config = PayconiqConfig::fromArray(['api_key' => 'key']);
        $gateway = new PayconiqGateway($config, $this->clock);

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessage('merchant ID');

        $gateway->createIntent(Money::of(1000, Currency::EUR), 'key');
    }

    #[Test]
    public function createIntentFailsWithoutApiKey(): void
    {
        $config = PayconiqConfig::fromArray(['merchant_id' => 'merch']);
        $gateway = new PayconiqGateway($config, $this->clock);

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessage('API key');

        $gateway->createIntent(Money::of(1000, Currency::EUR), 'key');
    }

    #[Test]
    public function captureIntentReturnsPendingCharge(): void
    {
        $charge = $this->gateway->captureIntent('pcq_intent_1', 'key');

        self::assertStringStartsWith('pcq_ch_', $charge->id);
        self::assertSame('pcq_intent_1', $charge->intentId);
        self::assertSame(ChargeStatus::Pending, $charge->status);
        self::assertSame('payconiq', $charge->provider);
    }

    #[Test]
    public function cancelIntentReturnsCancelledStatus(): void
    {
        $intent = $this->gateway->cancelIntent('pcq_intent_1', 'key');

        self::assertSame('pcq_intent_1', $intent->id);
        self::assertSame(PaymentIntentStatus::Cancelled, $intent->status);
    }

    #[Test]
    public function refundReturnsPendingRefund(): void
    {
        $refund = $this->gateway->refund('pcq_ch_1', Money::of(500, Currency::EUR), 'key');

        self::assertStringStartsWith('pcq_rf_', $refund->id);
        self::assertSame('pcq_ch_1', $refund->chargeId);
        self::assertSame(500, $refund->amount->amount);
        self::assertSame(RefundStatus::Pending, $refund->status);
    }

    #[Test]
    public function getIntentThrowsNotFound(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('not found');

        $this->gateway->getIntent('nonexistent');
    }

    #[Test]
    public function getChargeThrowsNotFound(): void
    {
        $this->expectException(PaymentException::class);
        $this->gateway->getCharge('nonexistent');
    }

    #[Test]
    public function getRefundThrowsNotFound(): void
    {
        $this->expectException(PaymentException::class);
        $this->gateway->getRefund('nonexistent');
    }

    #[Test]
    public function createIntentIncludesMetadataPassthrough(): void
    {
        $amount = Money::of(1000, Currency::EUR);
        $intent = $this->gateway->createIntent($amount, 'key', ['order_id' => 'ord_42']);

        self::assertSame('ord_42', $intent->metadata['order_id']);
    }
}
