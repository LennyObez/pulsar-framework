<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Tests\Unit\Internal\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Tickets\Contracts\TicketMessageRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketNumberGeneratorInterface;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Domain\TicketStatus;
use Pulsar\Extension\Tickets\Event\TicketAssigned;
use Pulsar\Extension\Tickets\Event\TicketCreated;
use Pulsar\Extension\Tickets\Event\TicketMessageAdded;
use Pulsar\Extension\Tickets\Event\TicketPriorityChanged;
use Pulsar\Extension\Tickets\Event\TicketStatusChanged;
use Pulsar\Extension\Tickets\Exception\TicketException;
use Pulsar\Extension\Tickets\Internal\Service\TicketService;

#[CoversClass(TicketService::class)]
final class TicketServiceTest extends TestCase
{
    private TicketRepositoryInterface&Stub $ticketRepo;
    private TicketMessageRepositoryInterface&Stub $messageRepo;
    private TicketNumberGeneratorInterface&Stub $numberGenerator;
    private EventDispatcherInterface&Stub $eventDispatcher;

    protected function setUp(): void
    {
        $this->ticketRepo = $this->createStub(TicketRepositoryInterface::class);
        $this->messageRepo = $this->createStub(TicketMessageRepositoryInterface::class);
        $this->numberGenerator = $this->createStub(TicketNumberGeneratorInterface::class);
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
    }

    #[Test]
    public function createReturnsTicketWithGeneratedNumber(): void
    {
        $this->numberGenerator->method('next')->willReturn('TKT-2026-000001');

        $service = $this->createService();
        $ticket = $service->create(
            subject: 'Help needed',
            description: 'Something is broken',
            reporterEmail: 'user@test.com',
            reporterName: 'User',
        );

        self::assertSame('TKT-2026-000001', $ticket->ticketNumber);
        self::assertSame('Help needed', $ticket->subject);
        self::assertSame(TicketStatus::Open, $ticket->status);
        self::assertSame(TicketPriority::Normal, $ticket->priority);
    }

    #[Test]
    public function createWithPriorityAndCategory(): void
    {
        $this->numberGenerator->method('next')->willReturn('TKT-2026-000002');

        $service = $this->createService();
        $ticket = $service->create(
            subject: 'Critical',
            description: 'Production down',
            reporterEmail: 'admin@test.com',
            reporterName: 'Admin',
            priority: TicketPriority::Critical,
            categoryId: 'cat-1',
            reporterId: 'user-42',
            tags: ['production'],
        );

        self::assertSame(TicketPriority::Critical, $ticket->priority);
        self::assertSame('cat-1', $ticket->categoryId);
        self::assertSame('user-42', $ticket->reporterId);
        self::assertSame(['production'], $ticket->tags);
    }

    #[Test]
    public function createDispatchesTicketCreatedEvent(): void
    {
        $this->numberGenerator->method('next')->willReturn('TKT-2026-000001');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(TicketCreated::class));

        $service = new TicketService(
            $this->ticketRepo,
            $this->messageRepo,
            $this->numberGenerator,
            $dispatcher,
        );

        $service->create('Test', 'Desc', 'a@b.com', 'Name');
    }

    #[Test]
    public function assignSetsAssigneeAndDispatchesEvent(): void
    {
        $ticket = $this->createOpenTicket();
        $this->ticketRepo->method('findById')->willReturn($ticket);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(TicketAssigned::class));

        $service = new TicketService(
            $this->ticketRepo,
            $this->messageRepo,
            $this->numberGenerator,
            $dispatcher,
        );

        $result = $service->assign('ticket-1', 'agent-1');

        self::assertSame('agent-1', $result->assigneeId);
    }

    #[Test]
    public function changeStatusDispatchesStatusChangedEvent(): void
    {
        $ticket = $this->createOpenTicket();
        $this->ticketRepo->method('findById')->willReturn($ticket);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (object $event): bool {
                return $event instanceof TicketStatusChanged
                    && $event->previousStatus === TicketStatus::Open
                    && $event->ticket->status === TicketStatus::InProgress;
            }));

        $service = new TicketService(
            $this->ticketRepo,
            $this->messageRepo,
            $this->numberGenerator,
            $dispatcher,
        );

        $service->changeStatus('ticket-1', TicketStatus::InProgress);
    }

    #[Test]
    public function changeStatusThrowsOnInvalidTransition(): void
    {
        $ticket = $this->createOpenTicket();
        $this->ticketRepo->method('findById')->willReturn($ticket);

        $service = $this->createService();

        $this->expectException(TicketException::class);
        $service->changeStatus('ticket-1', TicketStatus::Reopened);
    }

    #[Test]
    public function addMessageSavesAndDispatchesEvent(): void
    {
        $ticket = $this->createOpenTicket();
        $this->ticketRepo->method('findById')->willReturn($ticket);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(TicketMessageAdded::class));

        $service = new TicketService(
            $this->ticketRepo,
            $this->messageRepo,
            $this->numberGenerator,
            $dispatcher,
        );

        $message = $service->addMessage('ticket-1', 'user-1', 'User', 'Hello');

        self::assertSame('ticket-1', $message->ticketId);
        self::assertSame('Hello', $message->body);
        self::assertFalse($message->isInternal);
    }

    #[Test]
    public function addMessageThrowsWhenTicketClosed(): void
    {
        $ticket = $this->createOpenTicket()->close();
        $this->ticketRepo->method('findById')->willReturn($ticket);

        $service = $this->createService();

        $this->expectException(TicketException::class);
        $service->addMessage('ticket-1', null, 'User', 'Body');
    }

    #[Test]
    public function addInternalNoteCreatesInternalMessage(): void
    {
        $ticket = $this->createOpenTicket();
        $this->ticketRepo->method('findById')->willReturn($ticket);

        $service = $this->createService();
        $note = $service->addInternalNote('ticket-1', 'agent-1', 'Agent', 'Internal note');

        self::assertTrue($note->isInternal);
        self::assertSame('agent-1', $note->authorId);
    }

    #[Test]
    public function resolveChangesStatusToResolved(): void
    {
        $ticket = $this->createOpenTicket();
        $this->ticketRepo->method('findById')->willReturn($ticket);

        $service = $this->createService();
        $result = $service->resolve('ticket-1');

        self::assertSame(TicketStatus::Resolved, $result->status);
    }

    #[Test]
    public function closeChangesStatusToClosed(): void
    {
        $ticket = $this->createOpenTicket();
        $this->ticketRepo->method('findById')->willReturn($ticket);

        $service = $this->createService();
        $result = $service->close('ticket-1');

        self::assertSame(TicketStatus::Closed, $result->status);
    }

    #[Test]
    public function reopenChangesStatusToReopened(): void
    {
        $ticket = $this->createOpenTicket()->resolve();
        $this->ticketRepo->method('findById')->willReturn($ticket);

        $service = $this->createService();
        $result = $service->reopen('ticket-1');

        self::assertSame(TicketStatus::Reopened, $result->status);
    }

    #[Test]
    public function escalateIncreasesPriorityByOneLevel(): void
    {
        $ticket = $this->createOpenTicket();
        $this->ticketRepo->method('findById')->willReturn($ticket);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(TicketPriorityChanged::class));

        $service = new TicketService(
            $this->ticketRepo,
            $this->messageRepo,
            $this->numberGenerator,
            $dispatcher,
        );

        $result = $service->escalate('ticket-1');

        self::assertSame(TicketPriority::High, $result->priority);
    }

    #[Test]
    public function escalateDoesNotExceedCritical(): void
    {
        $ticket = Ticket::create(
            id: 'ticket-1',
            ticketNumber: 'TKT-2026-000001',
            subject: 'Test',
            description: 'Desc',
            reporterEmail: 'a@b.com',
            reporterName: 'A',
            priority: TicketPriority::Critical,
        );
        $this->ticketRepo->method('findById')->willReturn($ticket);

        $service = $this->createService();
        $result = $service->escalate('ticket-1');

        self::assertSame(TicketPriority::Critical, $result->priority);
    }

    #[Test]
    public function escalateWithNewAssignee(): void
    {
        $ticket = $this->createOpenTicket();
        $this->ticketRepo->method('findById')->willReturn($ticket);

        $service = $this->createService();
        $result = $service->escalate('ticket-1', 'senior-agent');

        self::assertSame('senior-agent', $result->assigneeId);
    }

    #[Test]
    public function changePriorityDispatchesEventWhenChanged(): void
    {
        $ticket = $this->createOpenTicket();
        $this->ticketRepo->method('findById')->willReturn($ticket);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(TicketPriorityChanged::class));

        $service = new TicketService(
            $this->ticketRepo,
            $this->messageRepo,
            $this->numberGenerator,
            $dispatcher,
        );

        $result = $service->changePriority('ticket-1', TicketPriority::Urgent);

        self::assertSame(TicketPriority::Urgent, $result->priority);
    }

    #[Test]
    public function findOrFailThrowsWhenNotFound(): void
    {
        $this->ticketRepo->method('findById')->willReturn(null);

        $service = $this->createService();

        $this->expectException(TicketException::class);
        $service->assign('nonexistent', 'agent-1');
    }

    private function createService(): TicketService
    {
        return new TicketService(
            $this->ticketRepo,
            $this->messageRepo,
            $this->numberGenerator,
            $this->eventDispatcher,
        );
    }

    private function createOpenTicket(): Ticket
    {
        return Ticket::create(
            id: 'ticket-1',
            ticketNumber: 'TKT-2026-000001',
            subject: 'Test',
            description: 'Description',
            reporterEmail: 'test@example.com',
            reporterName: 'Test User',
        );
    }
}
