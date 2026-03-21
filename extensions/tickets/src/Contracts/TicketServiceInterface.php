<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketMessage;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Domain\TicketStatus;

/**
 * High-level service interface for ticket operations.
 *
 * Orchestrates domain logic, dispatches events, and enforces SLA rules.
 * @api
 */
#[Api(since: '1.0.0')]
interface TicketServiceInterface
{
    /**
     * Create a new support ticket.
     *
     * @param list<string> $tags
     */
    public function create(
        string $subject,
        string $description,
        string $reporterEmail,
        string $reporterName,
        TicketPriority $priority = TicketPriority::Normal,
        ?string $categoryId = null,
        ?string $reporterId = null,
        array $tags = [],
    ): Ticket;

    /**
     * Assign a ticket to an agent.
     */
    public function assign(string $ticketId, string $assigneeId): Ticket;

    /**
     * Change the status of a ticket.
     */
    public function changeStatus(string $ticketId, TicketStatus $newStatus): Ticket;

    /**
     * Add a public message to a ticket.
     *
     * @param list<string> $attachments
     */
    public function addMessage(
        string $ticketId,
        ?string $authorId,
        string $authorName,
        string $body,
        array $attachments = [],
    ): TicketMessage;

    /**
     * Add an internal note (admin-only) to a ticket.
     *
     * @param list<string> $attachments
     */
    public function addInternalNote(
        string $ticketId,
        string $authorId,
        string $authorName,
        string $body,
        array $attachments = [],
    ): TicketMessage;

    /**
     * Resolve a ticket.
     */
    public function resolve(string $ticketId): Ticket;

    /**
     * Close a ticket.
     */
    public function close(string $ticketId): Ticket;

    /**
     * Reopen a previously resolved or closed ticket.
     */
    public function reopen(string $ticketId): Ticket;

    /**
     * Escalate a ticket: increases priority and optionally reassigns.
     */
    public function escalate(string $ticketId, ?string $newAssigneeId = null): Ticket;

    /**
     * Change the priority of a ticket.
     */
    public function changePriority(string $ticketId, TicketPriority $newPriority): Ticket;
}
