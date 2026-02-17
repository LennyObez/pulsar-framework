<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\EscalationRule;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketMessage;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Domain\TicketStatus;
use Pulsar\Extension\Tickets\Event\TicketAssigned;
use Pulsar\Extension\Tickets\Event\TicketCreated;
use Pulsar\Extension\Tickets\Event\TicketEscalated;
use Pulsar\Extension\Tickets\Event\TicketMessageAdded;
use Pulsar\Extension\Tickets\Event\TicketPriorityChanged;
use Pulsar\Extension\Tickets\Event\TicketStatusChanged;

final class EventTest extends TestCase
{
    #[Test]
    public function ticketCreatedExposesTicket(): void
    {
        $ticket = $this->createTicket();
        $event = new TicketCreated($ticket);

        self::assertSame($ticket, $event->ticket);
    }

    #[Test]
    public function ticketAssignedExposesPreviousAssignee(): void
    {
        $ticket = $this->createTicket();
        $event = new TicketAssigned($ticket, 'old-agent');

        self::assertSame($ticket, $event->ticket);
        self::assertSame('old-agent', $event->previousAssigneeId);
    }

    #[Test]
    public function ticketAssignedWithNullPreviousAssignee(): void
    {
        $ticket = $this->createTicket();
        $event = new TicketAssigned($ticket, null);

        self::assertNull($event->previousAssigneeId);
    }

    #[Test]
    public function ticketStatusChangedExposesPreviousStatus(): void
    {
        $ticket = $this->createTicket();
        $event = new TicketStatusChanged($ticket, TicketStatus::Open);

        self::assertSame(TicketStatus::Open, $event->previousStatus);
    }

    #[Test]
    public function ticketPriorityChangedExposesPreviousPriority(): void
    {
        $ticket = $this->createTicket();
        $event = new TicketPriorityChanged($ticket, TicketPriority::Low);

        self::assertSame(TicketPriority::Low, $event->previousPriority);
    }

    #[Test]
    public function ticketMessageAddedExposesMessage(): void
    {
        $ticket = $this->createTicket();
        $message = TicketMessage::create('msg-1', 'ticket-1', 'user-1', 'User', 'Hello');
        $event = new TicketMessageAdded($ticket, $message);

        self::assertSame($ticket, $event->ticket);
        self::assertSame($message, $event->message);
    }

    #[Test]
    public function ticketEscalatedExposesRuleAndElapsedTime(): void
    {
        $ticket = $this->createTicket();
        $rule = new EscalationRule(60, 'notify_manager', 'mgr@test.com');
        $event = new TicketEscalated($ticket, $rule, 75);

        self::assertSame($ticket, $event->ticket);
        self::assertSame($rule, $event->triggeredRule);
        self::assertSame(75, $event->elapsedMinutes);
    }

    private function createTicket(): Ticket
    {
        return Ticket::create(
            id: 'ticket-1',
            ticketNumber: 'TKT-2026-000001',
            subject: 'Test',
            description: 'Desc',
            reporterEmail: 'test@example.com',
            reporterName: 'Test',
        );
    }
}
