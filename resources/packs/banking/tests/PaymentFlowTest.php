<?php

declare(strict_types=1);

namespace Tests\Unit\Entity;

use {{namespace}}\Entity\PaymentIntent;
use {{namespace}}\Entity\PaymentIntentStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentIntent::class)]
final class PaymentFlowTest extends TestCase
{
    #[Test]
    public function it_creates_a_payment_intent(): void
    {
        $intent = new PaymentIntent(
            id: 'pi_001',
            amountCents: 5000,
            currency: 'USD',
            payerAccount: 'acc_payer',
        );

        self::assertSame('pi_001', $intent->id);
        self::assertSame(5000, $intent->amountCents);
        self::assertSame(PaymentIntentStatus::Created, $intent->status);
    }

    #[Test]
    public function it_requires_sca_before_confirmation(): void
    {
        $intent = new PaymentIntent(
            id: 'pi_002',
            amountCents: 10000,
            currency: 'EUR',
            payerAccount: 'acc_payer',
            scaRequired: true,
            scaCompleted: false,
        );

        self::assertFalse($intent->canConfirm());
    }

    #[Test]
    public function it_allows_confirmation_after_sca(): void
    {
        $intent = new PaymentIntent(
            id: 'pi_003',
            amountCents: 10000,
            currency: 'EUR',
            payerAccount: 'acc_payer',
            scaRequired: true,
            scaCompleted: true,
        );

        self::assertTrue($intent->canConfirm());
    }

    // TODO: Add tests for expiration, cancellation, and idempotency
}
