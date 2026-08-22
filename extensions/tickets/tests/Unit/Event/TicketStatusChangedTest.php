<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketStatus;
use Pulsar\Extension\Tickets\Event\TicketStatusChanged;

#[CoversClass(TicketStatusChanged::class)]
final class TicketStatusChangedTest extends TestCase
{
    #[Test]
    public function constructorSetsTicketAndPreviousStatus(): void
    {
        $ticket = Ticket::create('t-1', 'TKT-2026-000001', 'Subject', 'Desc', 'a@b.com', 'Name');
        $event = new TicketStatusChanged($ticket, TicketStatus::Open);

        self::assertSame($ticket, $event->ticket);
        self::assertSame(TicketStatus::Open, $event->previousStatus);
    }

    #[Test]
    public function previousStatusCanBeResolved(): void
    {
        $ticket = Ticket::create('t-2', 'TKT-2026-000002', 'Subject', 'Desc', 'a@b.com', 'Name');
        $event = new TicketStatusChanged($ticket, TicketStatus::Resolved);

        self::assertSame(TicketStatus::Resolved, $event->previousStatus);
    }
}
