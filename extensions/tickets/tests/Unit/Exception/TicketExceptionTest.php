<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Exception\TicketException;

final class TicketExceptionTest extends TestCase
{
    #[Test]
    public function notFoundContainsEntityAndId(): void
    {
        $e = TicketException::notFound('Ticket', 'abc-123');

        self::assertStringContainsString('Ticket', $e->getMessage());
        self::assertStringContainsString('abc-123', $e->getMessage());
    }

    #[Test]
    public function invalidTransitionContainsStatuses(): void
    {
        $e = TicketException::invalidTransition('open', 'reopened');

        self::assertStringContainsString('open', $e->getMessage());
        self::assertStringContainsString('reopened', $e->getMessage());
    }

    #[Test]
    public function concurrencyConflictContainsIdAndVersion(): void
    {
        $e = TicketException::concurrencyConflict('ticket-42', 3);

        self::assertStringContainsString('ticket-42', $e->getMessage());
        self::assertStringContainsString('3', $e->getMessage());
    }

    #[Test]
    public function unauthorizedContainsAction(): void
    {
        $e = TicketException::unauthorized('assign');

        self::assertStringContainsString('assign', $e->getMessage());
    }

    #[Test]
    public function ticketClosedContainsTicketId(): void
    {
        $e = TicketException::ticketClosed('ticket-99');

        self::assertStringContainsString('ticket-99', $e->getMessage());
    }

    #[Test]
    public function slaViolationContainsDetails(): void
    {
        $e = TicketException::slaViolation('ticket-1', 'first_response', 120);

        self::assertStringContainsString('ticket-1', $e->getMessage());
        self::assertStringContainsString('first_response', $e->getMessage());
        self::assertStringContainsString('120', $e->getMessage());
    }

    #[Test]
    public function invalidTicketNumberContainsNumber(): void
    {
        $e = TicketException::invalidTicketNumber('BAD-FORMAT');

        self::assertStringContainsString('BAD-FORMAT', $e->getMessage());
    }

    #[Test]
    public function categoryNotFoundContainsId(): void
    {
        $e = TicketException::categoryNotFound('cat-missing');

        self::assertStringContainsString('cat-missing', $e->getMessage());
    }

    #[Test]
    public function allFactoriesReturnTicketException(): void
    {
        self::assertInstanceOf(TicketException::class, TicketException::notFound('T', '1'));
        self::assertInstanceOf(TicketException::class, TicketException::invalidTransition('a', 'b'));
        self::assertInstanceOf(TicketException::class, TicketException::concurrencyConflict('x', 1));
        self::assertInstanceOf(TicketException::class, TicketException::unauthorized('act'));
        self::assertInstanceOf(TicketException::class, TicketException::ticketClosed('t'));
        self::assertInstanceOf(TicketException::class, TicketException::slaViolation('t', 's', 1));
        self::assertInstanceOf(TicketException::class, TicketException::invalidTicketNumber('n'));
        self::assertInstanceOf(TicketException::class, TicketException::categoryNotFound('c'));
    }
}
