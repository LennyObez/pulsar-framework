<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Event\TicketAssigned;

#[CoversClass(TicketAssigned::class)]
final class TicketAssignedTest extends TestCase
{
    #[Test]
    public function constructorSetsTicketAndPreviousAssignee(): void
    {
        $ticket = Ticket::create('t-1', 'TKT-2026-000001', 'Subject', 'Desc', 'a@b.com', 'Name');
        $event = new TicketAssigned($ticket, 'old-agent-id');

        self::assertSame($ticket, $event->ticket);
        self::assertSame('old-agent-id', $event->previousAssigneeId);
    }

    #[Test]
    public function previousAssigneeCanBeNull(): void
    {
        $ticket = Ticket::create('t-2', 'TKT-2026-000002', 'Subject', 'Desc', 'a@b.com', 'Name');
        $event = new TicketAssigned($ticket, null);

        self::assertNull($event->previousAssigneeId);
    }
}
