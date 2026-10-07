<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Internal\Service;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Tickets\Contracts\TicketNumberGeneratorInterface;

use function sprintf;
use function str_pad;

use const STR_PAD_LEFT;

/**
 * Generates sequential ticket numbers in the format TKT-YYYY-NNNNNN.
 *
 * Uses a database counter table to ensure uniqueness across concurrent requests.
 */
#[Internal(reason: 'Ticket number generation; implementation detail')]
final readonly class TicketNumberGenerator implements TicketNumberGeneratorInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * Generate the next ticket number for the current year.
     */
    public function next(): string
    {
        $year = (int) date('Y');

        // Atomically increment the counter
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO ticket_number_counters (year, next_number)
                VALUES (:year, 2)
                ON CONFLICT (year) DO UPDATE SET next_number = ticket_number_counters.next_number + 1
                SQL,
            ['year' => $year],
        );

        $result = $this->connection->query(
            'SELECT next_number - 1 AS current_number FROM ticket_number_counters WHERE year = :year',
            ['year' => $year],
        );

        $number = $result->first()?->getInt('current_number') ?? 1;

        return sprintf('TKT-%d-%s', $year, str_pad((string) $number, 6, '0', STR_PAD_LEFT));
    }
}
