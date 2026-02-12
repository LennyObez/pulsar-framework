<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\DisputeStatus;

final class DisputeStatusTest extends TestCase
{
    #[Test]
    public function openCanTransitionToUnderReviewOrAccepted(): void
    {
        self::assertTrue(DisputeStatus::Open->canTransitionTo(DisputeStatus::UnderReview));
        self::assertTrue(DisputeStatus::Open->canTransitionTo(DisputeStatus::Accepted));
        self::assertFalse(DisputeStatus::Open->canTransitionTo(DisputeStatus::Won));
        self::assertFalse(DisputeStatus::Open->canTransitionTo(DisputeStatus::Lost));
    }

    #[Test]
    public function underReviewCanTransitionToWonOrLost(): void
    {
        self::assertTrue(DisputeStatus::UnderReview->canTransitionTo(DisputeStatus::Won));
        self::assertTrue(DisputeStatus::UnderReview->canTransitionTo(DisputeStatus::Lost));
        self::assertFalse(DisputeStatus::UnderReview->canTransitionTo(DisputeStatus::Open));
        self::assertFalse(DisputeStatus::UnderReview->canTransitionTo(DisputeStatus::Accepted));
    }

    #[Test]
    public function terminalStatesCannotTransition(): void
    {
        self::assertFalse(DisputeStatus::Won->canTransitionTo(DisputeStatus::Open));
        self::assertFalse(DisputeStatus::Lost->canTransitionTo(DisputeStatus::Open));
        self::assertFalse(DisputeStatus::Accepted->canTransitionTo(DisputeStatus::Open));
    }
}
