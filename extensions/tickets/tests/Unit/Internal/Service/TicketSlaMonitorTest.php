<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Tickets\Config\TicketsConfig;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Domain\EscalationRule;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Domain\TicketSla;
use Pulsar\Extension\Tickets\Domain\TicketStatus;
use Pulsar\Extension\Tickets\Event\TicketEscalated;
use Pulsar\Extension\Tickets\Internal\Service\TicketSlaMonitor;

final class TicketSlaMonitorTest extends TestCase
{
    #[Test]
    public function checkViolationsReturnsZeroWhenNoSlaRules(): void
    {
        $config = new TicketsConfig();
        $ticketRepo = $this->createStub(TicketRepositoryInterface::class);
        $ticketRepo->method('findByStatus')->willReturn(new PaginationResult(
            items: [],
            total: 0,
            hasMore: false,
            perPage: 100,
        ));

        $dispatcher = $this->createStub(EventDispatcherInterface::class);

        $monitor = new TicketSlaMonitor($config, $ticketRepo, $dispatcher);

        self::assertSame(0, $monitor->checkViolations());
    }

    #[Test]
    public function checkViolationsDispatchesEscalationEvent(): void
    {
        $now = new DateTimeImmutable();
        $twoHoursAgo = new DateTimeImmutable('-2 hours');

        $ticket = new Ticket(
            id: 't1',
            ticketNumber: 'TKT-2026-000001',
            subject: 'Test',
            description: 'Desc',
            status: TicketStatus::Open,
            priority: TicketPriority::High,
            categoryId: null,
            assigneeId: null,
            reporterId: null,
            reporterEmail: 'a@b.com',
            reporterName: 'User',
            tags: [],
            createdAt: $twoHoursAgo,
            updatedAt: $twoHoursAgo,
            resolvedAt: null,
            closedAt: null,
        );

        $config = new TicketsConfig(
            slaRules: [
                new TicketSla(
                    priority: TicketPriority::High,
                    firstResponseMinutes: 30,
                    resolutionMinutes: 120,
                    escalationRules: [
                        new EscalationRule(60, 'notify_manager', null),
                    ],
                ),
            ],
        );

        $ticketRepo = $this->createStub(TicketRepositoryInterface::class);
        $ticketRepo->method('findByStatus')->willReturnCallback(
            function (TicketStatus $status) use ($ticket): PaginationResult {
                if ($status === TicketStatus::Open) {
                    return new PaginationResult(
                        items: [$ticket],
                        total: 1,
                        hasMore: false,
                        perPage: 100,
                    );
                }

                return new PaginationResult(items: [], total: 0, hasMore: false, perPage: 100);
            },
        );

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (object $event): bool {
                return $event instanceof TicketEscalated
                    && $event->elapsedMinutes >= 120
                    && $event->triggeredRule->action === 'notify_manager';
            }));

        $monitor = new TicketSlaMonitor($config, $ticketRepo, $dispatcher);
        $count = $monitor->checkViolations();

        self::assertSame(1, $count);
    }

    #[Test]
    public function checkViolationsSkipsTicketsWithNoMatchingSla(): void
    {
        $ticket = new Ticket(
            id: 't1',
            ticketNumber: 'TKT-2026-000001',
            subject: 'Test',
            description: 'Desc',
            status: TicketStatus::Open,
            priority: TicketPriority::Low,
            categoryId: null,
            assigneeId: null,
            reporterId: null,
            reporterEmail: 'a@b.com',
            reporterName: 'User',
            tags: [],
            createdAt: new DateTimeImmutable('-5 hours'),
            updatedAt: new DateTimeImmutable('-5 hours'),
            resolvedAt: null,
            closedAt: null,
        );

        // SLA only defined for Critical, not Low
        $config = new TicketsConfig(
            slaRules: [
                new TicketSla(
                    priority: TicketPriority::Critical,
                    firstResponseMinutes: 15,
                    resolutionMinutes: 60,
                    escalationRules: [
                        new EscalationRule(30, 'notify_manager', null),
                    ],
                ),
            ],
        );

        $ticketRepo = $this->createStub(TicketRepositoryInterface::class);
        $ticketRepo->method('findByStatus')->willReturnCallback(
            function (TicketStatus $status) use ($ticket): PaginationResult {
                if ($status === TicketStatus::Open) {
                    return new PaginationResult(
                        items: [$ticket],
                        total: 1,
                        hasMore: false,
                        perPage: 100,
                    );
                }

                return new PaginationResult(items: [], total: 0, hasMore: false, perPage: 100);
            },
        );

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $monitor = new TicketSlaMonitor($config, $ticketRepo, $dispatcher);
        $count = $monitor->checkViolations();

        self::assertSame(0, $count);
    }
}
