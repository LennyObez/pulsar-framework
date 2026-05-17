<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use Pulsar\Api\Api;

/**
 * Logs SQL query executions with safe defaults that never expose
 * raw parameter values in production environments.
 * @api
 */
#[Api(since: '1.0.0')]
interface SqlLoggerInterface
{
    /**
     * Log a SQL query execution.
     *
     * @param array<string|int, mixed> $bindings
     */
    public function log(string $sql, array $bindings, float $durationMs, int $rowCount): void;
}
