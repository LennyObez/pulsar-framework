<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Contracts;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketStatus;

#[CoversNothing]
final class TicketRepositoryInterfaceTest extends TestCase
{
    #[Test]
    public function stubCanReturnTicketById(): void
    {
        $ticket = $this->createTicket();

        $stub = $this->createStub(TicketRepositoryInterface::class);
        $stub->method('findById')->willReturn($ticket);

        self::assertSame($ticket, $stub->findById('ticket-1'));
    }

    #[Test]
    public function stubCanReturnNullById(): void
    {
        $stub = $this->createStub(TicketRepositoryInterface::class);
        $stub->method('findById')->willReturn(null);

        self::assertNull($stub->findById('nonexistent'));
    }

    #[Test]
    public function stubCanReturnTicketByNumber(): void
    {
        $ticket = $this->createTicket();

        $stub = $this->createStub(TicketRepositoryInterface::class);
        $stub->method('findByNumber')->willReturn($ticket);

        self::assertSame('TKT-2026-000001', $stub->findByNumber('TKT-2026-000001')->ticketNumber);
    }

    #[Test]
    public function stubCanReturnPaginatedByStatus(): void
    {
        $result = new PaginationResult(items: [], total: 0, hasMore: false, perPage: 25, currentPage: 1, lastPage: 1);

        $stub = $this->createStub(TicketRepositoryInterface::class);
        $stub->method('findByStatus')->willReturn($result);

        self::assertSame(0, $stub->findByStatus(TicketStatus::Open)->total);
    }

    #[Test]
    public function stubCanReturnPaginatedByAssignee(): void
    {
        $ticket = $this->createTicket();
        $result = new PaginationResult(items: [$ticket], total: 1, hasMore: false, perPage: 25, currentPage: 1, lastPage: 1);

        $stub = $this->createStub(TicketRepositoryInterface::class);
        $stub->method('findByAssignee')->willReturn($result);

        self::assertSame(1, $stub->findByAssignee('agent-1')->total);
    }

    #[Test]
    public function stubCanReturnStatusCounts(): void
    {
        $stub = $this->createStub(TicketRepositoryInterface::class);
        $stub->method('countByStatus')->willReturn(['open' => 5, 'closed' => 10]);

        $counts = $stub->countByStatus();
        self::assertSame(5, $counts['open']);
        self::assertSame(10, $counts['closed']);
    }

    #[Test]
    public function stubCanReturnResolvedTodayCount(): void
    {
        $stub = $this->createStub(TicketRepositoryInterface::class);
        $stub->method('countResolvedToday')->willReturn(3);

        self::assertSame(3, $stub->countResolvedToday());
    }

    #[Test]
    public function stubCanReturnSearchResults(): void
    {
        $ticket = $this->createTicket();
        $result = new PaginationResult(items: [$ticket], total: 1, hasMore: false, perPage: 25, currentPage: 1, lastPage: 1);

        $stub = $this->createStub(TicketRepositoryInterface::class);
        $stub->method('search')->willReturn($result);

        self::assertCount(1, $stub->search('billing issue')->items);
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
