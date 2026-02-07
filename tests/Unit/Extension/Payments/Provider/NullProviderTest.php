<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Provider;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
<<<<<<< feat/modular-monolith-payments
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
=======
use Pulsar\Extension\Payments\Clock\FixedClock;
use Pulsar\Extension\Payments\Contract\PaymentProviderInterface;
>>>>>>> main
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;
<<<<<<< feat/modular-monolith-payments
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;
use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\NullProvider;
=======
use Pulsar\Extension\Payments\Provider\NullProvider;
>>>>>>> main
use Pulsar\Tests\Unit\Extension\Payments\Contract\PaymentProviderContractTestCase;

#[CoversClass(NullProvider::class)]
final class NullProviderTest extends PaymentProviderContractTestCase
{
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2025-01-01T00:00:00Z'));
    }

    protected function createProvider(): PaymentProviderInterface
    {
        return new NullProvider($this->clock);
    }

    #[Test]
    public function nameIsNull(): void
    {
        $provider = new NullProvider($this->clock);

        self::assertSame('null', $provider->name());
    }

    #[Test]
    public function captureIntentReturnsSucceeded(): void
    {
        $provider = new NullProvider($this->clock);
        $intent = $provider->createIntent(Money::of(1000, Currency::USD), 'key-1');

        $charge = $provider->captureIntent($intent->id, 'key-2');

        self::assertSame(ChargeStatus::Succeeded, $charge->status);
        self::assertSame($intent->id, $charge->intentId);
    }

    #[Test]
    public function refundReturnsSucceeded(): void
    {
        $provider = new NullProvider($this->clock);
        $amount = Money::of(500, Currency::EUR);

        $refund = $provider->refund('ch_test', $amount, 'key-3');

        self::assertSame(RefundStatus::Succeeded, $refund->status);
        self::assertSame('ch_test', $refund->chargeId);
    }

    #[Test]
    public function getIntentThrowsNotFound(): void
    {
        $provider = new NullProvider($this->clock);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('not found');

        $provider->getIntent('nonexistent');
    }

    #[Test]
    public function getChargeThrowsNotFound(): void
    {
        $provider = new NullProvider($this->clock);

        $this->expectException(PaymentException::class);

        $provider->getCharge('nonexistent');
    }

    #[Test]
    public function getRefundThrowsNotFound(): void
    {
        $provider = new NullProvider($this->clock);

        $this->expectException(PaymentException::class);

        $provider->getRefund('nonexistent');
    }

    #[Test]
    public function deterministicIds(): void
    {
        $provider = new NullProvider($this->clock);

        $intent1 = $provider->createIntent(Money::of(100, Currency::USD), 'same-key');
        $intent2 = $provider->createIntent(Money::of(200, Currency::USD), 'same-key');

        // Same idempotency key produces same ID
        self::assertSame($intent1->id, $intent2->id);
    }
}
