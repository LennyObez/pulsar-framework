<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Event\TicketCreated;

#[CoversClass(TicketCreated::class)]
final class TicketCreatedTest extends TestCase
{
    #[Test]
    public function constructorSetsTicket(): void
    {
        $ticket = Ticket::create('t-1', 'TKT-2026-000001', 'Subject', 'Desc', 'a@b.com', 'Name');
        $event = new TicketCreated($ticket);

        self::assertSame($ticket, $event->ticket);
        self::assertSame('t-1', $event->ticket->id);
    }
}
