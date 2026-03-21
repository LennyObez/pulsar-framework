<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Contracts;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Extension\Tickets\Domain\Ticket;
use Pulsar\Extension\Tickets\Domain\TicketPriority;
use Pulsar\Extension\Tickets\Domain\TicketStatus;

/**
 * Repository interface for the Ticket aggregate root.
 * @api
 */
#[Api(since: '1.0.0')]
interface TicketRepositoryInterface
{
    public function findById(string $id): ?Ticket;

    /**
     * Find a ticket by its human-readable number (TKT-YYYY-NNNNNN).
     */
    public function findByNumber(string $ticketNumber): ?Ticket;

    /**
     * Find tickets by status with pagination.
     *
     * @return PaginationResult<Ticket>
     */
    public function findByStatus(
        TicketStatus $status,
        int $page = 1,
        int $perPage = 25,
    ): PaginationResult;

    /**
     * Find tickets assigned to a specific agent.
     *
     * @return PaginationResult<Ticket>
     */
    public function findByAssignee(
        string $assigneeId,
        int $page = 1,
        int $perPage = 25,
    ): PaginationResult;

    /**
     * Find tickets by reporter email.
     *
     * @return PaginationResult<Ticket>
     */
    public function findByReporter(
        string $reporterEmail,
        int $page = 1,
        int $perPage = 25,
    ): PaginationResult;

    /**
     * Search tickets by subject or description.
     *
     * @return PaginationResult<Ticket>
     */
    public function search(
        string $query,
        int $page = 1,
        int $perPage = 25,
    ): PaginationResult;

    /**
     * List tickets with optional filters.
     *
     * @return PaginationResult<Ticket>
     */
    public function findAll(
        int $page = 1,
        int $perPage = 25,
        ?TicketStatus $status = null,
        ?TicketPriority $priority = null,
        ?string $categoryId = null,
        ?string $assigneeId = null,
    ): PaginationResult;

    /**
     * Count tickets by status for dashboard stats.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array;

    /**
     * Count tickets resolved today.
     */
    public function countResolvedToday(): int;

    public function save(Ticket $ticket): void;

    public function delete(Ticket $ticket): void;
}
