<?php

declare(strict_types=1);

namespace Pulsar\Database\Health;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;

/**
 * Contract for checking database connection health.
 */
#[Api(since: '1.0.0')]
interface ConnectionHealthCheckerInterface
{
    /**
     * Check if the given connection is healthy and responsive.
     */
    public function isHealthy(ConnectionInterface $connection): bool;
}
