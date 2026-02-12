<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntent;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;

final class PaymentIntentTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $intent = $this->createIntent();

        self::assertSame('pi_123', $intent->id);
        self::assertSame(5000, $intent->amount->amount);
        self::assertSame(PaymentIntentStatus::Created, $intent->status);
        self::assertSame('test_provider', $intent->provider);
        self::assertSame('idem_key_1', $intent->idempotencyKey);
    }

    #[Test]
    public function transitionFromCreatedToCaptured(): void
    {
        $intent = $this->createIntent();
        $captured = $intent->transitionTo(PaymentIntentStatus::Captured);

        self::assertSame(PaymentIntentStatus::Captured, $captured->status);
        self::assertSame(PaymentIntentStatus::Created, $intent->status); // immutable
    }

    #[Test]
    public function transitionFromCreatedToCancelled(): void
    {
        $intent = $this->createIntent();
        $cancelled = $intent->transitionTo(PaymentIntentStatus::Cancelled);

        self::assertSame(PaymentIntentStatus::Cancelled, $cancelled->status);
    }

    #[Test]
    public function transitionFromCapturedToDisputed(): void
    {
        $intent = $this->createIntent()->transitionTo(PaymentIntentStatus::Captured);
        $disputed = $intent->transitionTo(PaymentIntentStatus::Disputed);

        self::assertSame(PaymentIntentStatus::Disputed, $disputed->status);
    }

    #[Test]
    public function transitionFromDisputedToResolved(): void
    {
        $intent = $this->createIntent()
            ->transitionTo(PaymentIntentStatus::Captured)
            ->transitionTo(PaymentIntentStatus::Disputed);

        $resolved = $intent->transitionTo(PaymentIntentStatus::Resolved);
        self::assertSame(PaymentIntentStatus::Resolved, $resolved->status);
    }

    #[Test]
    public function invalidTransitionFromCreatedToDisputedThrows(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Invalid');
        (void) $this->createIntent()->transitionTo(PaymentIntentStatus::Disputed);
    }

    #[Test]
    public function invalidTransitionFromCancelledThrows(): void
    {
        $cancelled = $this->createIntent()->transitionTo(PaymentIntentStatus::Cancelled);

        $this->expectException(PaymentException::class);
        (void) $cancelled->transitionTo(PaymentIntentStatus::Captured);
    }

    #[Test]
    public function invalidTransitionFromResolvedThrows(): void
    {
        $resolved = $this->createIntent()
            ->transitionTo(PaymentIntentStatus::Captured)
            ->transitionTo(PaymentIntentStatus::Disputed)
            ->transitionTo(PaymentIntentStatus::Resolved);

        $this->expectException(PaymentException::class);
        (void) $resolved->transitionTo(PaymentIntentStatus::Created);
    }

    private function createIntent(): PaymentIntent
    {
        return new PaymentIntent(
            id: 'pi_123',
            amount: Money::of(5000, Currency::USD),
            status: PaymentIntentStatus::Created,
            provider: 'test_provider',
            idempotencyKey: 'idem_key_1',
            createdAt: new DateTimeImmutable('2024-01-01'),
        );
    }
}
