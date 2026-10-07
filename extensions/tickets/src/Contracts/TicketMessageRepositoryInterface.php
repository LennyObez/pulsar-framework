<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Tickets\Domain\TicketMessage;

/**
 * Repository interface for ticket messages.
 * @api
 */
#[Api(since: '1.0.0')]
interface TicketMessageRepositoryInterface
{
    /**
     * Find all messages for a ticket, ordered by creation date ascending.
     *
     * @param bool $includeInternal Whether to include internal (admin-only) notes
     * @return list<TicketMessage>
     */
    public function findByTicket(string $ticketId, bool $includeInternal = false): array;

    public function save(TicketMessage $message): void;

    /**
     * Count messages for a ticket.
     */
    public function countByTicket(string $ticketId): int;
}
