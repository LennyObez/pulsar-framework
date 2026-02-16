<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Database\Driver;

/**
 * Audits database connection lifecycle events.
 */
#[Api(since: '1.0.0')]
final readonly class ConnectionAuditor implements ConnectionAuditorInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function logConnect(string $connectionName, Driver $driver): void
    {
        $this->logger->info('Database connection established', [
            'connection' => $connectionName,
            'driver' => $driver->value,
        ]);
    }

    #[Override]
    public function logDisconnect(string $connectionName): void
    {
        $this->logger->info('Database connection closed', [
            'connection' => $connectionName,
        ]);
    }

    #[Override]
    public function logError(string $connectionName, string $error): void
    {
        $this->logger->error('Database connection error', [
            'connection' => $connectionName,
            'error' => $error,
        ]);
    }

    #[Override]
    public function logFailover(string $from, string $to, string $reason): void
    {
        $this->logger->warning('Database connection failover', [
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
        ]);
    }
}
