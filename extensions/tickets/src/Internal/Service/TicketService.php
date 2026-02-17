<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Internal\Service;

use Pulsar\Api\Internal;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Tickets\Contracts\TicketMessageRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketNumberGeneratorInterface;
use Pulsar\Extension\Tickets\Contracts\TicketRepositoryInterface;
use Pulsar\Extension\Tickets\Contracts\TicketServiceInterface;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketMessage;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Domain\TicketStatus;
use Pulsar\Extension\Tickets\Event\TicketAssigned;
use Pulsar\Extension\Tickets\Event\TicketCreated;
use Pulsar\Extension\Tickets\Event\TicketMessageAdded;
use Pulsar\Extension\Tickets\Event\TicketPriorityChanged;
use Pulsar\Extension\Tickets\Event\TicketStatusChanged;
use Pulsar\Extension\Tickets\Exception\TicketException;

use function bin2hex;
use function random_bytes;

/**
 * Core ticket service: orchestrates domain logic, persistence, and events.
 */
#[Internal(reason: 'Ticket service implementation; use TicketServiceInterface for public API')]
final readonly class TicketService implements TicketServiceInterface
{
    public function __construct(
        private TicketRepositoryInterface $ticketRepository,
        private TicketMessageRepositoryInterface $messageRepository,
        private TicketNumberGeneratorInterface $numberGenerator,
        private EventDispatcherInterface $eventDispatcher,
        private ?TicketAutoAssigner $autoAssigner = null,
    ) {}

    public function create(
        string $subject,
        string $description,
        string $reporterEmail,
        string $reporterName,
        TicketPriority $priority = TicketPriority::Normal,
        ?string $categoryId = null,
        ?string $reporterId = null,
        array $tags = [],
    ): Ticket {
        $id = self::generateId();
        $ticketNumber = $this->numberGenerator->next();

        $ticket = Ticket::create(
            id: $id,
            ticketNumber: $ticketNumber,
            subject: $subject,
            description: $description,
            reporterEmail: $reporterEmail,
            reporterName: $reporterName,
            priority: $priority,
            categoryId: $categoryId,
            reporterId: $reporterId,
            tags: $tags,
        );

        // Auto-assign if enabled
        if ($this->autoAssigner !== null) {
            $assigneeId = $this->autoAssigner->selectAgent();

            if ($assigneeId !== null) {
                $ticket = $ticket->assign($assigneeId);
            }
        }

        $this->ticketRepository->save($ticket);
        $this->eventDispatcher->dispatch(new TicketCreated($ticket));

        return $ticket;
    }

    public function assign(string $ticketId, string $assigneeId): Ticket
    {
        $ticket = $this->findOrFail($ticketId);
        $previousAssigneeId = $ticket->assigneeId;
        $ticket = $ticket->assign($assigneeId);

        $this->ticketRepository->save($ticket);
        $this->eventDispatcher->dispatch(new TicketAssigned($ticket, $previousAssigneeId));

        return $ticket;
    }

    public function changeStatus(string $ticketId, TicketStatus $newStatus): Ticket
    {
        $ticket = $this->findOrFail($ticketId);
        $previousStatus = $ticket->status;
        $ticket = $ticket->changeStatus($newStatus);

        $this->ticketRepository->save($ticket);
        $this->eventDispatcher->dispatch(new TicketStatusChanged($ticket, $previousStatus));

        return $ticket;
    }

    public function addMessage(
        string $ticketId,
        ?string $authorId,
        string $authorName,
        string $body,
        array $attachments = [],
    ): TicketMessage {
        $ticket = $this->findOrFail($ticketId);

        if ($ticket->isClosed()) {
            throw TicketException::ticketClosed($ticketId);
        }

        $message = TicketMessage::create(
            id: self::generateId(),
            ticketId: $ticketId,
            authorId: $authorId,
            authorName: $authorName,
            body: $body,
            attachments: $attachments,
        );

        $this->messageRepository->save($message);
        $this->eventDispatcher->dispatch(new TicketMessageAdded($ticket, $message));

        return $message;
    }

    public function addInternalNote(
        string $ticketId,
        string $authorId,
        string $authorName,
        string $body,
        array $attachments = [],
    ): TicketMessage {
        $this->findOrFail($ticketId);

        $message = TicketMessage::createInternal(
            id: self::generateId(),
            ticketId: $ticketId,
            authorId: $authorId,
            authorName: $authorName,
            body: $body,
            attachments: $attachments,
        );

        $this->messageRepository->save($message);

        return $message;
    }

    public function resolve(string $ticketId): Ticket
    {
        return $this->changeStatus($ticketId, TicketStatus::Resolved);
    }

    public function close(string $ticketId): Ticket
    {
        return $this->changeStatus($ticketId, TicketStatus::Closed);
    }

    public function reopen(string $ticketId): Ticket
    {
        return $this->changeStatus($ticketId, TicketStatus::Reopened);
    }

    public function escalate(string $ticketId, ?string $newAssigneeId = null): Ticket
    {
        $ticket = $this->findOrFail($ticketId);
        $previousPriority = $ticket->priority;

        // Increase priority by one level if not already critical
        $newPriority = match ($ticket->priority) {
            TicketPriority::Low => TicketPriority::Normal,
            TicketPriority::Normal => TicketPriority::High,
            TicketPriority::High => TicketPriority::Urgent,
            TicketPriority::Urgent, TicketPriority::Critical => TicketPriority::Critical,
        };

        $ticket = $ticket->changePriority($newPriority);

        if ($newAssigneeId !== null) {
            $ticket = $ticket->assign($newAssigneeId);
        }

        $this->ticketRepository->save($ticket);

        if ($previousPriority !== $newPriority) {
            $this->eventDispatcher->dispatch(new TicketPriorityChanged($ticket, $previousPriority));
        }

        return $ticket;
    }

    public function changePriority(string $ticketId, TicketPriority $newPriority): Ticket
    {
        $ticket = $this->findOrFail($ticketId);
        $previousPriority = $ticket->priority;
        $ticket = $ticket->changePriority($newPriority);

        $this->ticketRepository->save($ticket);

        if ($previousPriority !== $newPriority) {
            $this->eventDispatcher->dispatch(new TicketPriorityChanged($ticket, $previousPriority));
        }

        return $ticket;
    }

    private function findOrFail(string $ticketId): Ticket
    {
        $ticket = $this->ticketRepository->findById($ticketId);

        if ($ticket === null) {
            throw TicketException::notFound('Ticket', $ticketId);
        }

        return $ticket;
    }

    private static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
