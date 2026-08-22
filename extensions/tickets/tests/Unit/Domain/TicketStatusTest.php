<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\TicketStatus;

final class TicketStatusTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        $expected = [
            'open', 'in_progress', 'waiting_on_customer', 'waiting_on_agent',
            'resolved', 'closed', 'reopened',
        ];

        $actual = array_map(static fn(TicketStatus $s) => $s->value, TicketStatus::cases());

        self::assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{TicketStatus, TicketStatus, bool}>
     */
    public static function transitionProvider(): iterable
    {
        // Open transitions
        yield 'open -> in_progress' => [TicketStatus::Open, TicketStatus::InProgress, true];
        yield 'open -> waiting_on_customer' => [TicketStatus::Open, TicketStatus::WaitingOnCustomer, true];
        yield 'open -> resolved' => [TicketStatus::Open, TicketStatus::Resolved, true];
        yield 'open -> closed' => [TicketStatus::Open, TicketStatus::Closed, true];
        yield 'open -> reopened (invalid)' => [TicketStatus::Open, TicketStatus::Reopened, false];
        yield 'open -> open (self)' => [TicketStatus::Open, TicketStatus::Open, false];

        // Resolved transitions
        yield 'resolved -> closed' => [TicketStatus::Resolved, TicketStatus::Closed, true];
        yield 'resolved -> reopened' => [TicketStatus::Resolved, TicketStatus::Reopened, true];
        yield 'resolved -> open (invalid)' => [TicketStatus::Resolved, TicketStatus::Open, false];

        // Closed transitions
        yield 'closed -> reopened' => [TicketStatus::Closed, TicketStatus::Reopened, true];
        yield 'closed -> open (invalid)' => [TicketStatus::Closed, TicketStatus::Open, false];
        yield 'closed -> in_progress (invalid)' => [TicketStatus::Closed, TicketStatus::InProgress, false];

        // Reopened transitions
        yield 'reopened -> in_progress' => [TicketStatus::Reopened, TicketStatus::InProgress, true];
        yield 'reopened -> resolved' => [TicketStatus::Reopened, TicketStatus::Resolved, true];
        yield 'reopened -> closed' => [TicketStatus::Reopened, TicketStatus::Closed, true];
    }

    #[Test]
    #[DataProvider('transitionProvider')]
    public function canTransitionToReturnsExpected(TicketStatus $from, TicketStatus $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    #[Test]
    public function isActiveReturnsTrueForActiveStatuses(): void
    {
        self::assertTrue(TicketStatus::Open->isActive());
        self::assertTrue(TicketStatus::InProgress->isActive());
        self::assertTrue(TicketStatus::WaitingOnCustomer->isActive());
        self::assertTrue(TicketStatus::WaitingOnAgent->isActive());
        self::assertTrue(TicketStatus::Reopened->isActive());
    }

    #[Test]
    public function isActiveReturnsFalseForTerminalStatuses(): void
    {
        self::assertFalse(TicketStatus::Resolved->isActive());
        self::assertFalse(TicketStatus::Closed->isActive());
    }

    #[Test]
    public function labelReturnsHumanReadableString(): void
    {
        self::assertSame('Open', TicketStatus::Open->label());
        self::assertSame('In Progress', TicketStatus::InProgress->label());
        self::assertSame('Waiting on Customer', TicketStatus::WaitingOnCustomer->label());
        self::assertSame('Closed', TicketStatus::Closed->label());
    }

    #[Test]
    public function badgeVariantReturnsValidCssClass(): void
    {
        self::assertSame('info', TicketStatus::Open->badgeVariant());
        self::assertSame('primary', TicketStatus::InProgress->badgeVariant());
        self::assertSame('warning', TicketStatus::WaitingOnCustomer->badgeVariant());
        self::assertSame('success', TicketStatus::Resolved->badgeVariant());
        self::assertSame('secondary', TicketStatus::Closed->badgeVariant());
    }
}
