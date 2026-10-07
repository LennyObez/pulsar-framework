<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ReportStatus;

final class ReportStatusTest extends TestCase
{
    #[Test]
    public function pendingCanTransitionToUnderReviewAndDismissed(): void
    {
        self::assertTrue(ReportStatus::Pending->canTransitionTo(ReportStatus::UnderReview));
        self::assertTrue(ReportStatus::Pending->canTransitionTo(ReportStatus::Dismissed));
    }

    #[Test]
    public function pendingCannotTransitionToActioned(): void
    {
        self::assertFalse(ReportStatus::Pending->canTransitionTo(ReportStatus::Actioned));
    }

    #[Test]
    public function underReviewCanTransitionToActionedAndDismissed(): void
    {
        self::assertTrue(ReportStatus::UnderReview->canTransitionTo(ReportStatus::Actioned));
        self::assertTrue(ReportStatus::UnderReview->canTransitionTo(ReportStatus::Dismissed));
    }

    #[Test]
    public function terminalStatesCannotTransition(): void
    {
        self::assertFalse(ReportStatus::Actioned->canTransitionTo(ReportStatus::Pending));
        self::assertFalse(ReportStatus::Actioned->canTransitionTo(ReportStatus::UnderReview));
        self::assertFalse(ReportStatus::Actioned->canTransitionTo(ReportStatus::Dismissed));
        self::assertFalse(ReportStatus::Dismissed->canTransitionTo(ReportStatus::Pending));
        self::assertFalse(ReportStatus::Dismissed->canTransitionTo(ReportStatus::UnderReview));
        self::assertFalse(ReportStatus::Dismissed->canTransitionTo(ReportStatus::Actioned));
    }

    #[Test]
    public function cannotTransitionToSelf(): void
    {
        self::assertFalse(ReportStatus::Pending->canTransitionTo(ReportStatus::Pending));
        self::assertFalse(ReportStatus::UnderReview->canTransitionTo(ReportStatus::UnderReview));
    }

    #[Test]
    public function isTerminalForActionedAndDismissed(): void
    {
        self::assertTrue(ReportStatus::Actioned->isTerminal());
        self::assertTrue(ReportStatus::Dismissed->isTerminal());
        self::assertFalse(ReportStatus::Pending->isTerminal());
        self::assertFalse(ReportStatus::UnderReview->isTerminal());
    }

    #[Test]
    public function labelReturnsHumanReadable(): void
    {
        self::assertSame('Pending', ReportStatus::Pending->label());
        self::assertSame('Under Review', ReportStatus::UnderReview->label());
        self::assertSame('Actioned', ReportStatus::Actioned->label());
        self::assertSame('Dismissed', ReportStatus::Dismissed->label());
    }
}
