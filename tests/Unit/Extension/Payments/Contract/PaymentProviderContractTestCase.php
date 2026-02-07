<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Contract;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Contracts\PaymentProviderInterface;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;

/**
 * Abstract contract test for PaymentProviderInterface implementations.
 *
 * Subclasses provide the provider instance; this class verifies the contract.
 */
abstract class PaymentProviderContractTestCase extends TestCase
{
    abstract protected function createProvider(): PaymentProviderInterface;

    #[Test]
    public function nameReturnsNonEmptyString(): void
    {
        $provider = $this->createProvider();

        self::assertNotEmpty($provider->name());
    }

    #[Test]
    public function createIntentReturnsCreatedStatus(): void
    {
        $provider = $this->createProvider();
        $amount = Money::of(5000, Currency::USD);

        $intent = $provider->createIntent($amount, 'idem-create-1');

        self::assertSame(PaymentIntentStatus::Created, $intent->status);
        self::assertSame($provider->name(), $intent->provider);
        self::assertNotEmpty($intent->id);
    }

    #[Test]
    public function createIntentPreservesMetadata(): void
    {
        $provider = $this->createProvider();
        $amount = Money::of(2000, Currency::EUR);

        $intent = $provider->createIntent($amount, 'idem-create-meta', ['order_id' => '123']);

        self::assertSame(['order_id' => '123'], $intent->metadata);
    }

    #[Test]
    public function cancelIntentReturnsCancelledStatus(): void
    {
        $provider = $this->createProvider();
        $amount = Money::of(3000, Currency::USD);

        $intent = $provider->createIntent($amount, 'idem-cancel-1');
        $cancelled = $provider->cancelIntent($intent->id, 'idem-cancel-2');

        self::assertSame(PaymentIntentStatus::Cancelled, $cancelled->status);
    }
}
