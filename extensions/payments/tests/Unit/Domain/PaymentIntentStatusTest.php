<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\PaymentIntentStatus;

final class PaymentIntentStatusTest extends TestCase
{
    #[Test]
    public function createdCanTransitionToCapturedOrCancelled(): void
    {
        self::assertTrue(PaymentIntentStatus::Created->canTransitionTo(PaymentIntentStatus::Captured));
        self::assertTrue(PaymentIntentStatus::Created->canTransitionTo(PaymentIntentStatus::Cancelled));
        self::assertFalse(PaymentIntentStatus::Created->canTransitionTo(PaymentIntentStatus::Disputed));
        self::assertFalse(PaymentIntentStatus::Created->canTransitionTo(PaymentIntentStatus::Resolved));
    }

    #[Test]
    public function capturedCanTransitionToDisputedOnly(): void
    {
        self::assertTrue(PaymentIntentStatus::Captured->canTransitionTo(PaymentIntentStatus::Disputed));
        self::assertFalse(PaymentIntentStatus::Captured->canTransitionTo(PaymentIntentStatus::Cancelled));
        self::assertFalse(PaymentIntentStatus::Captured->canTransitionTo(PaymentIntentStatus::Resolved));
    }

    #[Test]
    public function disputedCanTransitionToResolvedOnly(): void
    {
        self::assertTrue(PaymentIntentStatus::Disputed->canTransitionTo(PaymentIntentStatus::Resolved));
        self::assertFalse(PaymentIntentStatus::Disputed->canTransitionTo(PaymentIntentStatus::Captured));
    }

    #[Test]
    public function terminalStatesCannotTransition(): void
    {
        self::assertFalse(PaymentIntentStatus::Cancelled->canTransitionTo(PaymentIntentStatus::Created));
        self::assertFalse(PaymentIntentStatus::Resolved->canTransitionTo(PaymentIntentStatus::Created));
    }
}
