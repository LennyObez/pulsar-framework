<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Contracts;

use Pulsar\Api\Api;

/**
 * Generates sequential ticket numbers.
 * @api
 */
#[Api(since: '1.0.0')]
interface TicketNumberGeneratorInterface
{
    /**
     * Generate the next ticket number for the current year.
     */
    public function next(): string;
}
