<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Provider;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Contracts\ClockInterface;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\SimulatorProvider;

final class SimulatorProviderTest extends TestCase
{
    private SimulatorProvider $provider;

    protected function setUp(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-01-01'));
        $this->provider = new SimulatorProvider($clock);
    }

    #[Test]
    public function nameReturnsSimulator(): void
    {
        self::assertSame('simulator', $this->provider->name());
    }

    #[Test]
    public function createIntentSucceedsForNormalAmount(): void
    {
        $intent = $this->provider->createIntent(
            Money::of(5000, Currency::USD),
            'key-1',
        );

        self::assertSame(PaymentIntentStatus::Created, $intent->status);
        self::assertSame('simulator', $intent->provider);
        self::assertSame(5000, $intent->amount->amount);
    }

    #[Test]
    #[DataProvider('declineAmountsProvider')]
    public function createIntentDeclinesTestVectorAmounts(int $amount): void
    {
        $this->expectException(PaymentProviderException::class);

        $this->provider->createIntent(Money::of($amount, Currency::USD), 'key-fail');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function declineAmountsProvider(): iterable
    {
        yield 'insufficient_funds' => [9999];
        yield 'card_expired' => [9998];
        yield 'card_declined' => [9997];
        yield 'processing_error' => [9996];
        yield 'fraud_suspected' => [9995];
        yield 'timeout' => [9994];
        yield 'network_error' => [9993];
        yield 'rate_limited' => [9992];
    }

    #[Test]
    public function captureIntentReturnsSucceededCharge(): void
    {
        $intent = $this->provider->createIntent(Money::of(1000, Currency::EUR), 'key-cap');
        $charge = $this->provider->captureIntent($intent->id, 'key-cap-2');

        self::assertSame(ChargeStatus::Succeeded, $charge->status);
        self::assertSame($intent->id, $charge->intentId);
        self::assertSame(1000, $charge->amount->amount);
    }

    #[Test]
    public function captureIntentThrowsForUnknownIntent(): void
    {
        $this->expectException(PaymentException::class);
        $this->provider->captureIntent('nonexistent', 'key');
    }

    #[Test]
    public function cancelIntentTransitionsToCancelled(): void
    {
        $intent = $this->provider->createIntent(Money::of(2000, Currency::GBP), 'key-cancel');
        $cancelled = $this->provider->cancelIntent($intent->id, 'key-cancel-2');

        self::assertSame(PaymentIntentStatus::Cancelled, $cancelled->status);
    }

    #[Test]
    public function refundSucceedsForNormalCharge(): void
    {
        $intent = $this->provider->createIntent(Money::of(5000, Currency::USD), 'key-ref');
        $charge = $this->provider->captureIntent($intent->id, 'key-ref-cap');
        $refund = $this->provider->refund($charge->id, null, 'key-ref-ref');

        self::assertSame(RefundStatus::Succeeded, $refund->status);
        self::assertSame(5000, $refund->amount->amount);
    }

    #[Test]
    public function refundFailsFor3030Amount(): void
    {
        $intent = $this->provider->createIntent(Money::of(3030, Currency::USD), 'key-3030');
        $charge = $this->provider->captureIntent($intent->id, 'key-3030-cap');

        $this->expectException(PaymentProviderException::class);
        $this->provider->refund($charge->id, null, 'key-3030-ref');
    }

    #[Test]
    public function partialRefundUsesProvidedAmount(): void
    {
        $intent = $this->provider->createIntent(Money::of(5000, Currency::USD), 'key-part');
        $charge = $this->provider->captureIntent($intent->id, 'key-part-cap');
        $refund = $this->provider->refund($charge->id, Money::of(2000, Currency::USD), 'key-part-ref');

        self::assertSame(2000, $refund->amount->amount);
    }

    #[Test]
    public function getIntentReturnsStoredIntent(): void
    {
        $created = $this->provider->createIntent(Money::of(1000, Currency::USD), 'key-get');
        $retrieved = $this->provider->getIntent($created->id);

        self::assertSame($created->id, $retrieved->id);
    }

    #[Test]
    public function getChargeReturnsStoredCharge(): void
    {
        $intent = $this->provider->createIntent(Money::of(1000, Currency::USD), 'k');
        $charge = $this->provider->captureIntent($intent->id, 'k2');
        $retrieved = $this->provider->getCharge($charge->id);

        self::assertSame($charge->id, $retrieved->id);
    }

    #[Test]
    public function getRefundReturnsStoredRefund(): void
    {
        $intent = $this->provider->createIntent(Money::of(1000, Currency::USD), 'r1');
        $charge = $this->provider->captureIntent($intent->id, 'r2');
        $refund = $this->provider->refund($charge->id, null, 'r3');
        $retrieved = $this->provider->getRefund($refund->id);

        self::assertSame($refund->id, $retrieved->id);
    }

    #[Test]
    public function hasDisputeFlagTrueFor4242Amount(): void
    {
        $intent = $this->provider->createIntent(Money::of(4242, Currency::USD), 'dsp');
        $charge = $this->provider->captureIntent($intent->id, 'dsp-cap');

        self::assertTrue($this->provider->hasDisputeFlag($charge->id));
    }

    #[Test]
    public function hasDisputeFlagFalseForNormalAmount(): void
    {
        $intent = $this->provider->createIntent(Money::of(5000, Currency::USD), 'nodsp');
        $charge = $this->provider->captureIntent($intent->id, 'nodsp-cap');

        self::assertFalse($this->provider->hasDisputeFlag($charge->id));
    }
}
