<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Event\TicketPriorityChanged;

#[CoversClass(TicketPriorityChanged::class)]
final class TicketPriorityChangedTest extends TestCase
{
    #[Test]
    public function constructorSetsTicketAndPreviousPriority(): void
    {
        $ticket = Ticket::create('t-1', 'TKT-2026-000001', 'Subject', 'Desc', 'a@b.com', 'Name');
        $event = new TicketPriorityChanged($ticket, TicketPriority::Low);

        self::assertSame($ticket, $event->ticket);
        self::assertSame(TicketPriority::Low, $event->previousPriority);
    }

    #[Test]
    public function previousPriorityCanBeCritical(): void
    {
        $ticket = Ticket::create('t-2', 'TKT-2026-000002', 'Subject', 'Desc', 'a@b.com', 'Name');
        $event = new TicketPriorityChanged($ticket, TicketPriority::Critical);

        self::assertSame(TicketPriority::Critical, $event->previousPriority);
    }
}
