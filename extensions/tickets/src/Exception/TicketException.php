<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Base exception for all ticketing errors.
 */
#[Api(since: '1.0.0')]
final class TicketException extends RuntimeException
{
    public static function notFound(string $entity, string $id): self
    {
        return new self("$entity not found: $id");
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Invalid status transition from '$from' to '$to'");
    }

    public static function concurrencyConflict(string $entityId, int $expectedVersion): self
    {
        return new self(sprintf(
            'Concurrency conflict for ticket %s: expected version %d was already modified',
            $entityId,
            $expectedVersion,
        ));
    }

    public static function unauthorized(string $action): self
    {
        return new self("Unauthorized ticketing action: $action");
    }

    public static function ticketClosed(string $ticketId): self
    {
        return new self("Ticket is closed and does not accept new messages: $ticketId");
    }

    public static function slaViolation(string $ticketId, string $slaType, int $elapsedMinutes): self
    {
        return new self(sprintf(
            'SLA violation on ticket %s: %s exceeded after %d minutes',
            $ticketId,
            $slaType,
            $elapsedMinutes,
        ));
    }

    public static function invalidTicketNumber(string $number): self
    {
        return new self("Invalid ticket number format: $number");
    }

    public static function categoryNotFound(string $id): self
    {
        return new self("Ticket category not found: $id");
    }
}
