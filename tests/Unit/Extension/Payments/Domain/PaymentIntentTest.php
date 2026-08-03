<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentIntent;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;
use Pulsar\Extension\Payments\Exception\PaymentException;

#[CoversClass(PaymentIntent::class)]
#[CoversClass(PaymentIntentStatus::class)]
final class PaymentIntentTest extends TestCase
{
    #[Test]
    public function transitionFromCreatedToCaptured(): void
    {
        $intent = $this->createIntent(PaymentIntentStatus::Created);

        $captured = $intent->transitionTo(PaymentIntentStatus::Captured);

        self::assertSame(PaymentIntentStatus::Captured, $captured->status);
        self::assertSame($intent->id, $captured->id);
    }

    #[Test]
    public function transitionFromCreatedToCancelled(): void
    {
        $intent = $this->createIntent(PaymentIntentStatus::Created);

        $cancelled = $intent->transitionTo(PaymentIntentStatus::Cancelled);

        self::assertSame(PaymentIntentStatus::Cancelled, $cancelled->status);
    }

    #[Test]
    public function transitionFromCapturedToDisputed(): void
    {
        $intent = $this->createIntent(PaymentIntentStatus::Captured);

        $disputed = $intent->transitionTo(PaymentIntentStatus::Disputed);

        self::assertSame(PaymentIntentStatus::Disputed, $disputed->status);
    }

    #[Test]
    public function transitionFromDisputedToResolved(): void
    {
        $intent = $this->createIntent(PaymentIntentStatus::Disputed);

        $resolved = $intent->transitionTo(PaymentIntentStatus::Resolved);

        self::assertSame(PaymentIntentStatus::Resolved, $resolved->status);
    }

    #[Test]
    public function invalidTransitionThrows(): void
    {
        $intent = $this->createIntent(PaymentIntentStatus::Cancelled);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid PaymentIntent transition');

        (void) $intent->transitionTo(PaymentIntentStatus::Captured);
    }

    #[Test]
    public function transitionReturnsNewInstance(): void
    {
        $intent = $this->createIntent(PaymentIntentStatus::Created);

        $captured = $intent->transitionTo(PaymentIntentStatus::Captured);

        self::assertNotSame($intent, $captured);
        self::assertSame(PaymentIntentStatus::Created, $intent->status);
    }

    #[Test]
    public function cannotTransitionFromResolvedToAnything(): void
    {
        $intent = $this->createIntent(PaymentIntentStatus::Resolved);

        $this->expectException(PaymentException::class);

        (void) $intent->transitionTo(PaymentIntentStatus::Created);
    }

    #[Test]
    public function metadataIsPreserved(): void
    {
        $intent = new PaymentIntent(
            id: 'pi_test',
            amount: Money::of(1000, Currency::USD),
            status: PaymentIntentStatus::Created,
            provider: 'test',
            idempotencyKey: 'key-1',
            createdAt: new DateTimeImmutable(),
            metadata: ['order_id' => '12345'],
        );

        self::assertSame(['order_id' => '12345'], $intent->metadata);
    }

    private function createIntent(PaymentIntentStatus $status): PaymentIntent
    {
        return new PaymentIntent(
            id: 'pi_test',
            amount: Money::of(1000, Currency::USD),
            status: $status,
            provider: 'test',
            idempotencyKey: 'key-1',
            createdAt: new DateTimeImmutable(),
        );
    }
}
