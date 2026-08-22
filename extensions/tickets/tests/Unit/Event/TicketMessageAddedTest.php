<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketMessage;
use Pulsar\Extension\Tickets\Event\TicketMessageAdded;

#[CoversClass(TicketMessageAdded::class)]
final class TicketMessageAddedTest extends TestCase
{
    #[Test]
    public function constructorSetsTicketAndMessage(): void
    {
        $ticket = Ticket::create('t-1', 'TKT-2026-000001', 'Subject', 'Desc', 'a@b.com', 'Name');
        $message = TicketMessage::create('msg-1', 't-1', 'user-1', 'Alice', 'Message body');
        $event = new TicketMessageAdded($ticket, $message);

        self::assertSame($ticket, $event->ticket);
        self::assertSame($message, $event->message);
        self::assertSame('Message body', $event->message->body);
    }

    #[Test]
    public function internalMessageCanBeAttached(): void
    {
        $ticket = Ticket::create('t-2', 'TKT-2026-000002', 'Subject', 'Desc', 'a@b.com', 'Name');
        $note = TicketMessage::createInternal('msg-2', 't-2', 'admin-1', 'Admin', 'Private note');
        $event = new TicketMessageAdded($ticket, $note);

        self::assertTrue($event->message->isInternal);
    }
}
