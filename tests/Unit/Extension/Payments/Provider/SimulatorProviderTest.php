<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Provider;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\ChargeStatus;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Domain\RefundStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;
use Pulsar\Extension\Payments\Exception\PaymentProviderException;
use Pulsar\Extension\Payments\Internal\Infrastructure\Clock\FixedClock;
use Pulsar\Extension\Payments\Internal\Infrastructure\Provider\SimulatorProvider;
use Pulsar\Tests\Unit\Extension\Payments\Contract\PaymentProviderContractTestCase;

use function strlen;

#[CoversClass(SimulatorProvider::class)]
final class SimulatorProviderTest extends PaymentProviderContractTestCase
{
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(new DateTimeImmutable('2025-01-01T00:00:00Z'));
    }

    protected function createProvider(): PaymentProviderInterface
    {
        return new SimulatorProvider($this->clock);
    }

    #[Test]
    public function nameIsSimulator(): void
    {
        $provider = new SimulatorProvider($this->clock);

        self::assertSame('simulator', $provider->name());
    }

    #[Test]
    public function normalAmountSucceeds(): void
    {
        $provider = new SimulatorProvider($this->clock);

        $intent = $provider->createIntent(Money::of(5000, Currency::USD), 'key-1');

        self::assertSame(PaymentIntentStatus::Created, $intent->status);
    }

    #[Test]
    public function amount9999DeclinesInsufficientFunds(): void
    {
        $provider = new SimulatorProvider($this->clock);

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageIsOrContains('insufficient_funds');

        $provider->createIntent(Money::of(9999, Currency::USD), 'key-decline');
    }

    #[Test]
    public function amount9998DeclinesCardExpired(): void
    {
        $provider = new SimulatorProvider($this->clock);

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageIsOrContains('card_expired');

        $provider->createIntent(Money::of(9998, Currency::USD), 'key-expired');
    }

    #[Test]
    public function amount9997DeclinesCardDeclined(): void
    {
        $provider = new SimulatorProvider($this->clock);

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageIsOrContains('card_declined');

        $provider->createIntent(Money::of(9997, Currency::USD), 'key-declined');
    }

    #[Test]
    public function amount9996DeclinesProcessingError(): void
    {
        $provider = new SimulatorProvider($this->clock);

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageIsOrContains('processing_error');

        $provider->createIntent(Money::of(9996, Currency::USD), 'key-processing');
    }

    #[Test]
    public function amount9995DeclinesFraudSuspected(): void
    {
        $provider = new SimulatorProvider($this->clock);

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageIsOrContains('fraud_suspected');

        $provider->createIntent(Money::of(9995, Currency::USD), 'key-fraud');
    }

    #[Test]
    public function amount9994ThrowsTimeout(): void
    {
        $provider = new SimulatorProvider($this->clock);

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageIsOrContains('timed out');

        $provider->createIntent(Money::of(9994, Currency::USD), 'key-timeout');
    }

    #[Test]
    public function amount9993ThrowsNetworkError(): void
    {
        $provider = new SimulatorProvider($this->clock);

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageIsOrContains('network error');

        $provider->createIntent(Money::of(9993, Currency::USD), 'key-network');
    }

    #[Test]
    public function amount9992ThrowsRateLimited(): void
    {
        $provider = new SimulatorProvider($this->clock);

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageIsOrContains('rate limit');

        $provider->createIntent(Money::of(9992, Currency::USD), 'key-rate');
    }

    #[Test]
    public function captureAndGetIntent(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $intent = $provider->createIntent(Money::of(2000, Currency::USD), 'key-capture');

        $charge = $provider->captureIntent($intent->id, 'key-capture-2');

        self::assertSame(ChargeStatus::Succeeded, $charge->status);
        self::assertSame($intent->id, $charge->intentId);

        $retrieved = $provider->getIntent($intent->id);
        self::assertSame(PaymentIntentStatus::Captured, $retrieved->status);
    }

    #[Test]
    public function cancelAndGetIntent(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $intent = $provider->createIntent(Money::of(2000, Currency::USD), 'key-cancel');

        $cancelled = $provider->cancelIntent($intent->id, 'key-cancel-2');

        self::assertSame(PaymentIntentStatus::Cancelled, $cancelled->status);
    }

    #[Test]
    public function refundSucceeds(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $intent = $provider->createIntent(Money::of(2000, Currency::USD), 'key-refund');
        $charge = $provider->captureIntent($intent->id, 'key-refund-2');

        $refund = $provider->refund($charge->id, null, 'key-refund-3');

        self::assertSame(RefundStatus::Succeeded, $refund->status);
        self::assertSame($charge->id, $refund->chargeId);
    }

    #[Test]
    public function amount3030RefundFails(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $intent = $provider->createIntent(Money::of(3030, Currency::USD), 'key-3030');
        $charge = $provider->captureIntent($intent->id, 'key-3030-cap');

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageIsOrContains('simulated_refund_failure');

        $provider->refund($charge->id, null, 'key-3030-refund');
    }

    #[Test]
    public function amount4242FlagsForDispute(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $intent = $provider->createIntent(Money::of(4242, Currency::USD), 'key-4242');
        $charge = $provider->captureIntent($intent->id, 'key-4242-cap');

        self::assertTrue($provider->hasDisputeFlag($charge->id));
    }

    #[Test]
    public function getIntentThrowsForNonexistent(): void
    {
        $provider = new SimulatorProvider($this->clock);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageIsOrContains('not found');

        $provider->getIntent('nonexistent');
    }

    #[Test]
    public function deterministicIdsIncludeOperationAndResource(): void
    {
        $provider = new SimulatorProvider($this->clock);
        $intent = $provider->createIntent(Money::of(1000, Currency::USD), 'key-determ');
        $charge = $provider->captureIntent($intent->id, 'key-determ-2');

        // Charge ID should be deterministic
        self::assertSame(32, strlen($charge->id));

        // Different operation same key produces different ID
        self::assertNotSame($intent->id, $charge->id);
    }
}
