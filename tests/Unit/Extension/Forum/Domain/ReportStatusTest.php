<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Domain;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ReportStatus;

#[CoversNothing]
final class ReportStatusTest extends TestCase
{
    /** @return iterable<string, array{ReportStatus, ReportStatus, bool}> */
    public static function transitionProvider(): iterable
    {
        if (!enum_exists(ReportStatus::class)) {
            return;
        }

        yield 'pending to under_review' => [ReportStatus::Pending, ReportStatus::UnderReview, true];
        yield 'pending to dismissed' => [ReportStatus::Pending, ReportStatus::Dismissed, true];
        yield 'pending to actioned' => [ReportStatus::Pending, ReportStatus::Actioned, false];
        yield 'pending to pending' => [ReportStatus::Pending, ReportStatus::Pending, false];
        yield 'under_review to actioned' => [ReportStatus::UnderReview, ReportStatus::Actioned, true];
        yield 'under_review to dismissed' => [ReportStatus::UnderReview, ReportStatus::Dismissed, true];
        yield 'under_review to pending' => [ReportStatus::UnderReview, ReportStatus::Pending, false];
        yield 'actioned to any' => [ReportStatus::Actioned, ReportStatus::Pending, false];
        yield 'dismissed to any' => [ReportStatus::Dismissed, ReportStatus::Pending, false];
    }

    #[Test]
    #[DataProvider('transitionProvider')]
    public function canTransitionTo(ReportStatus $from, ReportStatus $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    #[Test]
    public function isTerminalForTerminalStates(): void
    {
        self::assertTrue(ReportStatus::Actioned->isTerminal());
        self::assertTrue(ReportStatus::Dismissed->isTerminal());
    }

    #[Test]
    public function isTerminalFalseForNonTerminalStates(): void
    {
        self::assertFalse(ReportStatus::Pending->isTerminal());
        self::assertFalse(ReportStatus::UnderReview->isTerminal());
    }

    #[Test]
    public function labelsAreHumanReadable(): void
    {
        self::assertSame('Pending', ReportStatus::Pending->label());
        self::assertSame('Under Review', ReportStatus::UnderReview->label());
        self::assertSame('Actioned', ReportStatus::Actioned->label());
        self::assertSame('Dismissed', ReportStatus::Dismissed->label());
    }
}
