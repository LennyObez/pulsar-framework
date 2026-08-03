<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Dispute;
use Pulsar\Extension\Payments\Domain\DisputeReason;
use Pulsar\Extension\Payments\Domain\DisputeStatus;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Exception\PaymentException;

#[CoversClass(Dispute::class)]
#[CoversClass(DisputeStatus::class)]
final class DisputeTest extends TestCase
{
    #[Test]
    public function transitionFromOpenToUnderReview(): void
    {
        $dispute = $this->createDispute(DisputeStatus::Open);

        $reviewed = $dispute->transitionTo(DisputeStatus::UnderReview);

        self::assertSame(DisputeStatus::UnderReview, $reviewed->status);
    }

    #[Test]
    public function transitionFromOpenToAccepted(): void
    {
        $dispute = $this->createDispute(DisputeStatus::Open);

        $accepted = $dispute->transitionTo(DisputeStatus::Accepted);

        self::assertSame(DisputeStatus::Accepted, $accepted->status);
    }

    #[Test]
    public function transitionFromUnderReviewToWon(): void
    {
        $dispute = $this->createDispute(DisputeStatus::UnderReview);

        $won = $dispute->transitionTo(DisputeStatus::Won);

        self::assertSame(DisputeStatus::Won, $won->status);
    }

    #[Test]
    public function transitionFromUnderReviewToLost(): void
    {
        $dispute = $this->createDispute(DisputeStatus::UnderReview);

        $lost = $dispute->transitionTo(DisputeStatus::Lost);

        self::assertSame(DisputeStatus::Lost, $lost->status);
    }

    #[Test]
    public function invalidTransitionThrows(): void
    {
        $dispute = $this->createDispute(DisputeStatus::Won);

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid Dispute transition');

        (void) $dispute->transitionTo(DisputeStatus::Open);
    }

    #[Test]
    public function transitionReturnsNewInstance(): void
    {
        $dispute = $this->createDispute(DisputeStatus::Open);

        $reviewed = $dispute->transitionTo(DisputeStatus::UnderReview);

        self::assertNotSame($dispute, $reviewed);
        self::assertSame(DisputeStatus::Open, $dispute->status);
    }

    #[Test]
    public function cannotTransitionFromAccepted(): void
    {
        $dispute = $this->createDispute(DisputeStatus::Accepted);

        $this->expectException(PaymentException::class);

        (void) $dispute->transitionTo(DisputeStatus::Won);
    }

    private function createDispute(DisputeStatus $status): Dispute
    {
        return new Dispute(
            id: 'dp_test',
            chargeId: 'ch_test',
            amount: Money::of(1000, Currency::USD),
            status: $status,
            reason: DisputeReason::Fraudulent,
            provider: 'test',
            createdAt: new DateTimeImmutable(),
        );
    }
}
