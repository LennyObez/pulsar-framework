<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Tickets\Exception\TicketException;

/**
 * Ticket aggregate root: represents a support ticket.
 *
 * Supports status transitions, priority changes, assignment,
 * tagging, and SLA tracking. Immutable: all mutations return
 * a new instance via clone-with.
 */
#[Api(since: '1.0.0')]
final readonly class Ticket
{
    /**
     * @param string $id UUIDv7
     * @param string $ticketNumber Human-readable ticket reference (TKT-YYYY-NNNNNN)
     * @param string $subject Ticket subject line
     * @param string $description Full ticket description
     * @param TicketStatus $status Current lifecycle status
     * @param TicketPriority $priority Triage priority level
     * @param string|null $categoryId FK to TicketCategory
     * @param string|null $assigneeId FK to agent user
     * @param string|null $reporterId FK to authenticated reporter user (null for guest)
     * @param string $reporterEmail Reporter contact email
     * @param string $reporterName Reporter display name
     * @param list<string> $tags Freeform tags for filtering
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param DateTimeImmutable $updatedAt Auto-managed update timestamp
     * @param DateTimeImmutable|null $resolvedAt When the ticket was resolved
     * @param DateTimeImmutable|null $closedAt When the ticket was closed
     * @param int $version Optimistic concurrency version
     */
    public function __construct(
        public string $id,
        public string $ticketNumber,
        public string $subject,
        public string $description,
        public TicketStatus $status,
        public TicketPriority $priority,
        public ?string $categoryId,
        public ?string $assigneeId,
        public ?string $reporterId,
        public string $reporterEmail,
        public string $reporterName,
        public array $tags,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $resolvedAt,
        public ?DateTimeImmutable $closedAt,
        public int $version = 1,
    ) {}

    /**
     * Create a new open ticket.
     *
     * @param list<string> $tags
     */
    public static function create(
        string $id,
        string $ticketNumber,
        string $subject,
        string $description,
        string $reporterEmail,
        string $reporterName,
        TicketPriority $priority = TicketPriority::Normal,
        ?string $categoryId = null,
        ?string $reporterId = null,
        array $tags = [],
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            ticketNumber: $ticketNumber,
            subject: $subject,
            description: $description,
            status: TicketStatus::Open,
            priority: $priority,
            categoryId: $categoryId,
            assigneeId: null,
            reporterId: $reporterId,
            reporterEmail: $reporterEmail,
            reporterName: $reporterName,
            tags: $tags,
            createdAt: $now,
            updatedAt: $now,
            resolvedAt: null,
            closedAt: null,
        );
    }

    /**
     * Assign the ticket to an agent.
     */
    #[NoDiscard]
    public function assign(string $assigneeId): static
    {
        return clone($this, [
            'assigneeId' => $assigneeId,
            'status' => $this->status === TicketStatus::Open ? TicketStatus::InProgress : $this->status,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Change the ticket status.
     *
     * @throws TicketException If the transition is invalid
     */
    #[NoDiscard]
    public function changeStatus(TicketStatus $newStatus): static
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            throw TicketException::invalidTransition($this->status->value, $newStatus->value);
        }

        $now = new DateTimeImmutable();
        $resolvedAt = $newStatus === TicketStatus::Resolved ? $now : $this->resolvedAt;
        $closedAt = $newStatus === TicketStatus::Closed ? $now : $this->closedAt;

        // Clear resolved/closed timestamps on reopen
        if ($newStatus === TicketStatus::Reopened) {
            $resolvedAt = null;
            $closedAt = null;
        }

        return clone($this, [
            'status' => $newStatus,
            'resolvedAt' => $resolvedAt,
            'closedAt' => $closedAt,
            'updatedAt' => $now,
        ]);
    }

    /**
     * Change the ticket priority.
     */
    #[NoDiscard]
    public function changePriority(TicketPriority $newPriority): static
    {
        return clone($this, [
            'priority' => $newPriority,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Move the ticket to a different category.
     */
    #[NoDiscard]
    public function changeCategory(?string $categoryId): static
    {
        return clone($this, [
            'categoryId' => $categoryId,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Replace the ticket tags.
     *
     * @param list<string> $tags
     */
    #[NoDiscard]
    public function setTags(array $tags): static
    {
        return clone($this, [
            'tags' => $tags,
            'updatedAt' => new DateTimeImmutable(),
        ]);
    }

    /**
     * Resolve the ticket.
     *
     * @throws TicketException If the transition is invalid
     */
    #[NoDiscard]
    public function resolve(): static
    {
        return $this->changeStatus(TicketStatus::Resolved);
    }

    /**
     * Close the ticket.
     *
     * @throws TicketException If the transition is invalid
     */
    #[NoDiscard]
    public function close(): static
    {
        return $this->changeStatus(TicketStatus::Closed);
    }

    /**
     * Reopen the ticket.
     *
     * @throws TicketException If the transition is invalid
     */
    #[NoDiscard]
    public function reopen(): static
    {
        return $this->changeStatus(TicketStatus::Reopened);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isAssigned(): bool
    {
        return $this->assigneeId !== null;
    }

    public function isResolved(): bool
    {
        return $this->status === TicketStatus::Resolved;
    }

    public function isClosed(): bool
    {
        return $this->status === TicketStatus::Closed;
    }
}
