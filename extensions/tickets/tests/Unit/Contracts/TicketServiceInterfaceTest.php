<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Contracts\TicketServiceInterface;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketMessage;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Domain\TicketStatus;

#[CoversNothing]
final class TicketServiceInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanCreateTicket(): void
    {
        $ticket = $this->createTicket();

        $stub = $this->createStub(TicketServiceInterface::class);
        $stub->method('create')->willReturn($ticket);

        $result = $stub->create(
            subject: 'Billing Issue',
            description: 'I was charged twice',
            reporterEmail: 'user@example.com',
            reporterName: 'Test User',
        );

        self::assertSame('Test Ticket', $result->subject);
        self::assertSame(TicketStatus::Open, $result->status);
    }

    #[Test]
    public function stubCanAssignTicket(): void
    {
        $ticket = $this->createTicket()->assign('agent-1');

        $stub = $this->createStub(TicketServiceInterface::class);
        $stub->method('assign')->willReturn($ticket);

        $result = $stub->assign('ticket-1', 'agent-1');
        self::assertSame('agent-1', $result->assigneeId);
    }

    #[Test]
    public function stubCanChangeStatus(): void
    {
        $ticket = $this->createTicket();

        $stub = $this->createStub(TicketServiceInterface::class);
        $stub->method('changeStatus')->willReturn($ticket);

        $result = $stub->changeStatus('ticket-1', TicketStatus::InProgress);
        self::assertNotNull($result);
    }

    #[Test]
    public function stubCanAddMessage(): void
    {
        $message = TicketMessage::create('msg-1', 'ticket-1', 'user-1', 'Alice', 'Help please');

        $stub = $this->createStub(TicketServiceInterface::class);
        $stub->method('addMessage')->willReturn($message);

        $result = $stub->addMessage('ticket-1', 'user-1', 'Alice', 'Help please');
        self::assertSame('Help please', $result->body);
        self::assertFalse($result->isInternal);
    }

    #[Test]
    public function stubCanAddInternalNote(): void
    {
        $note = TicketMessage::createInternal('msg-2', 'ticket-1', 'admin-1', 'Admin', 'Internal note');

        $stub = $this->createStub(TicketServiceInterface::class);
        $stub->method('addInternalNote')->willReturn($note);

        $result = $stub->addInternalNote('ticket-1', 'admin-1', 'Admin', 'Internal note');
        self::assertTrue($result->isInternal);
    }

    #[Test]
    public function stubCanResolveAndCloseAndReopen(): void
    {
        $ticket = $this->createTicket();

        $stub = $this->createStub(TicketServiceInterface::class);
        $stub->method('resolve')->willReturn($ticket);
        $stub->method('close')->willReturn($ticket);
        $stub->method('reopen')->willReturn($ticket);

        self::assertNotNull($stub->resolve('ticket-1'));
        self::assertNotNull($stub->close('ticket-1'));
        self::assertNotNull($stub->reopen('ticket-1'));
    }

    #[Test]
    public function stubCanEscalateTicket(): void
    {
        $ticket = $this->createTicket();

        $stub = $this->createStub(TicketServiceInterface::class);
        $stub->method('escalate')->willReturn($ticket);

        self::assertNotNull($stub->escalate('ticket-1', 'senior-agent'));
    }

    #[Test]
    public function stubCanChangePriority(): void
    {
        $ticket = $this->createTicket();

        $stub = $this->createStub(TicketServiceInterface::class);
        $stub->method('changePriority')->willReturn($ticket);

        self::assertNotNull($stub->changePriority('ticket-1', TicketPriority::Critical));
    }

    private function createTicket(): Ticket
    {
        return Ticket::create(
            id: 'ticket-1',
            ticketNumber: 'TKT-2026-000001',
            subject: 'Test Ticket',
            description: 'Description',
            reporterEmail: 'user@example.com',
            reporterName: 'Test User',
        );
    }
}
