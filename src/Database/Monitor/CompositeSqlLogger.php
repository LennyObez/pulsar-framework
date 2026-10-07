<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use Override;
use Pulsar\Api\Internal;

/**
 * Fans a query log out to several {@see SqlLoggerInterface} sinks (e.g. the
 * standard SQL logger plus the profiler), since {@see MonitoredConnection}
 * accepts a single logger.
 */
#[Internal]
final readonly class CompositeSqlLogger implements SqlLoggerInterface
{
    /**
     * @param list<SqlLoggerInterface> $loggers
     */
    public function __construct(
        private array $loggers,
    ) {}

    /**
     * @param array<array-key, mixed> $bindings
     */
    #[Override]
    public function log(string $sql, array $bindings, float $durationMs, int $rowCount): void
    {
        foreach ($this->loggers as $logger) {
            $logger->log($sql, $bindings, $durationMs, $rowCount);
        }
    }
}
