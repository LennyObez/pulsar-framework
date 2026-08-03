<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Domain\TicketStatus;
use Pulsar\Extension\Tickets\Exception\TicketException;

final class TicketTest extends TestCase
{
    #[Test]
    public function createSetsDefaults(): void
    {
        $ticket = Ticket::create(
            id: 'ticket-1',
            ticketNumber: 'TKT-2026-000001',
            subject: 'Test Ticket',
            description: 'This is a test ticket.',
            reporterEmail: 'user@example.com',
            reporterName: 'John Doe',
        );

        self::assertSame('ticket-1', $ticket->id);
        self::assertSame('TKT-2026-000001', $ticket->ticketNumber);
        self::assertSame('Test Ticket', $ticket->subject);
        self::assertSame('This is a test ticket.', $ticket->description);
        self::assertSame(TicketStatus::Open, $ticket->status);
        self::assertSame(TicketPriority::Normal, $ticket->priority);
        self::assertNull($ticket->categoryId);
        self::assertNull($ticket->assigneeId);
        self::assertNull($ticket->reporterId);
        self::assertSame('user@example.com', $ticket->reporterEmail);
        self::assertSame('John Doe', $ticket->reporterName);
        self::assertSame([], $ticket->tags);
        self::assertNull($ticket->resolvedAt);
        self::assertNull($ticket->closedAt);
        self::assertSame(1, $ticket->version);
    }

    #[Test]
    public function createWithAllParameters(): void
    {
        $ticket = Ticket::create(
            id: 'ticket-2',
            ticketNumber: 'TKT-2026-000002',
            subject: 'Urgent Issue',
            description: 'Production is down.',
            reporterEmail: 'admin@example.com',
            reporterName: 'Jane Admin',
            priority: TicketPriority::Critical,
            categoryId: 'cat-1',
            reporterId: 'user-42',
            tags: ['production', 'urgent'],
        );

        self::assertSame(TicketPriority::Critical, $ticket->priority);
        self::assertSame('cat-1', $ticket->categoryId);
        self::assertSame('user-42', $ticket->reporterId);
        self::assertSame(['production', 'urgent'], $ticket->tags);
    }

    #[Test]
    public function assignSetsAssigneeAndMovesToInProgress(): void
    {
        $ticket = $this->createOpenTicket();
        $assigned = $ticket->assign('agent-1');

        self::assertSame('agent-1', $assigned->assigneeId);
        self::assertSame(TicketStatus::InProgress, $assigned->status);
        self::assertNotSame($ticket, $assigned);
    }

    #[Test]
    public function assignDoesNotChangeStatusIfAlreadyInProgress(): void
    {
        $ticket = $this->createOpenTicket()->assign('agent-1');
        self::assertSame(TicketStatus::InProgress, $ticket->status);

        $reassigned = $ticket->assign('agent-2');
        self::assertSame('agent-2', $reassigned->assigneeId);
        self::assertSame(TicketStatus::InProgress, $reassigned->status);
    }

    #[Test]
    public function changeStatusToResolvedSetsResolvedAt(): void
    {
        $ticket = $this->createOpenTicket()->changeStatus(TicketStatus::Resolved);

        self::assertSame(TicketStatus::Resolved, $ticket->status);
        self::assertNotNull($ticket->resolvedAt);
        self::assertNull($ticket->closedAt);
    }

    #[Test]
    public function changeStatusToClosedSetsClosedAt(): void
    {
        $ticket = $this->createOpenTicket()->changeStatus(TicketStatus::Closed);

        self::assertSame(TicketStatus::Closed, $ticket->status);
        self::assertNotNull($ticket->closedAt);
    }

    #[Test]
    public function reopenClearsResolvedAndClosedTimestamps(): void
    {
        $resolved = $this->createOpenTicket()->changeStatus(TicketStatus::Resolved);
        self::assertNotNull($resolved->resolvedAt);

        $reopened = $resolved->changeStatus(TicketStatus::Reopened);
        self::assertSame(TicketStatus::Reopened, $reopened->status);
        self::assertNull($reopened->resolvedAt);
        self::assertNull($reopened->closedAt);
    }

    #[Test]
    public function invalidTransitionThrowsException(): void
    {
        $ticket = $this->createOpenTicket();

        $this->expectException(TicketException::class);
        $this->expectExceptionMessageIsOrContains("Invalid status transition from 'open' to 'reopened'");

        $ticket->changeStatus(TicketStatus::Reopened);
    }

    #[Test]
    public function changePriorityReturnsNewInstance(): void
    {
        $ticket = $this->createOpenTicket();
        $updated = $ticket->changePriority(TicketPriority::Urgent);

        self::assertSame(TicketPriority::Urgent, $updated->priority);
        self::assertNotSame($ticket, $updated);
    }

    #[Test]
    public function changeCategoryReturnsNewInstance(): void
    {
        $ticket = $this->createOpenTicket();
        $updated = $ticket->changeCategory('cat-new');

        self::assertSame('cat-new', $updated->categoryId);
        self::assertNotSame($ticket, $updated);
    }

    #[Test]
    public function setTagsReplacesExistingTags(): void
    {
        $ticket = Ticket::create(
            id: 't1',
            ticketNumber: 'TKT-2026-000001',
            subject: 'Test',
            description: 'Desc',
            reporterEmail: 'a@b.com',
            reporterName: 'A',
            tags: ['old'],
        );

        $updated = $ticket->setTags(['new-tag-1', 'new-tag-2']);

        self::assertSame(['new-tag-1', 'new-tag-2'], $updated->tags);
    }

    #[Test]
    public function resolveIsShortcutForChangeStatusToResolved(): void
    {
        $ticket = $this->createOpenTicket()->resolve();

        self::assertSame(TicketStatus::Resolved, $ticket->status);
        self::assertNotNull($ticket->resolvedAt);
    }

    #[Test]
    public function closeIsShortcutForChangeStatusToClosed(): void
    {
        $ticket = $this->createOpenTicket()->close();

        self::assertSame(TicketStatus::Closed, $ticket->status);
        self::assertNotNull($ticket->closedAt);
    }

    #[Test]
    public function reopenIsShortcutForChangeStatusToReopened(): void
    {
        $ticket = $this->createOpenTicket()->resolve()->reopen();

        self::assertSame(TicketStatus::Reopened, $ticket->status);
    }

    #[Test]
    public function isActiveReturnsTrueForOpenTicket(): void
    {
        self::assertTrue($this->createOpenTicket()->isActive());
    }

    #[Test]
    public function isActiveReturnsFalseForResolvedTicket(): void
    {
        self::assertFalse($this->createOpenTicket()->resolve()->isActive());
    }

    #[Test]
    public function isAssignedReturnsFalseForUnassignedTicket(): void
    {
        self::assertFalse($this->createOpenTicket()->isAssigned());
    }

    #[Test]
    public function isAssignedReturnsTrueAfterAssignment(): void
    {
        self::assertTrue($this->createOpenTicket()->assign('agent-1')->isAssigned());
    }

    #[Test]
    public function isResolvedReturnsTrueOnlyForResolvedStatus(): void
    {
        self::assertFalse($this->createOpenTicket()->isResolved());
        self::assertTrue($this->createOpenTicket()->resolve()->isResolved());
    }

    #[Test]
    public function isClosedReturnsTrueOnlyForClosedStatus(): void
    {
        self::assertFalse($this->createOpenTicket()->isClosed());
        self::assertTrue($this->createOpenTicket()->close()->isClosed());
    }

    private function createOpenTicket(): Ticket
    {
        return Ticket::create(
            id: 'ticket-test',
            ticketNumber: 'TKT-2026-000001',
            subject: 'Test',
            description: 'Test description',
            reporterEmail: 'test@example.com',
            reporterName: 'Test User',
        );
    }
}
