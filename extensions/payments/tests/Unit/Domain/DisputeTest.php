<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Dispute;
use Pulsar\Extension\Payments\Domain\DisputeReason;
use Pulsar\Extension\Payments\Domain\DisputeStatus;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Exception\PaymentException;

final class DisputeTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $dispute = $this->createDispute();

        self::assertSame('disp_1', $dispute->id);
        self::assertSame('ch_1', $dispute->chargeId);
        self::assertSame(5000, $dispute->amount->amount);
        self::assertSame(DisputeStatus::Open, $dispute->status);
        self::assertSame(DisputeReason::Fraudulent, $dispute->reason);
    }

    #[Test]
    public function transitionFromOpenToUnderReview(): void
    {
        $dispute = $this->createDispute();
        $reviewed = $dispute->transitionTo(DisputeStatus::UnderReview);

        self::assertSame(DisputeStatus::UnderReview, $reviewed->status);
        self::assertSame(DisputeStatus::Open, $dispute->status); // immutable
    }

    #[Test]
    public function transitionFromOpenToAccepted(): void
    {
        $dispute = $this->createDispute();
        $accepted = $dispute->transitionTo(DisputeStatus::Accepted);

        self::assertSame(DisputeStatus::Accepted, $accepted->status);
    }

    #[Test]
    public function transitionFromUnderReviewToWon(): void
    {
        $reviewed = $this->createDispute()->transitionTo(DisputeStatus::UnderReview);
        $won = $reviewed->transitionTo(DisputeStatus::Won);

        self::assertSame(DisputeStatus::Won, $won->status);
    }

    #[Test]
    public function transitionFromUnderReviewToLost(): void
    {
        $reviewed = $this->createDispute()->transitionTo(DisputeStatus::UnderReview);
        $lost = $reviewed->transitionTo(DisputeStatus::Lost);

        self::assertSame(DisputeStatus::Lost, $lost->status);
    }

    #[Test]
    public function invalidTransitionFromOpenToWonThrows(): void
    {
        $this->expectException(PaymentException::class);
        (void) $this->createDispute()->transitionTo(DisputeStatus::Won);
    }

    #[Test]
    public function terminalStatesCannotTransition(): void
    {
        $won = $this->createDispute()
            ->transitionTo(DisputeStatus::UnderReview)
            ->transitionTo(DisputeStatus::Won);

        $this->expectException(PaymentException::class);
        (void) $won->transitionTo(DisputeStatus::Open);
    }

    private function createDispute(): Dispute
    {
        return new Dispute(
            id: 'disp_1',
            chargeId: 'ch_1',
            amount: Money::of(5000, Currency::USD),
            status: DisputeStatus::Open,
            reason: DisputeReason::Fraudulent,
            provider: 'test',
            createdAt: new DateTimeImmutable('2024-01-01'),
        );
    }
}
