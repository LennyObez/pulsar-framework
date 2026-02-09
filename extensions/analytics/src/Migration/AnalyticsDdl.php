<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Migration;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;

/**
 * Multi-driver DDL adapter for analytics migrations.
 *
 * Handles common type substitutions across MySQL, PostgreSQL, and SQLite.
 * Structural differences (indexes, constraints) are handled per-migration
 * with driver conditionals.
 */
#[Internal(reason: 'Migration helper — not part of the public analytics API')]
final readonly class AnalyticsDdl
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function driverName(): string
    {
        return $this->connection->driver()->value;
    }

    public function driver(): Driver
    {
        return $this->connection->driver();
    }

    /**
     * Adapt a base SQL statement (written for MySQL) to the target driver.
     *
     * Handles: TINYINT(1), TIMESTAMP, JSON, DECIMAL, AUTO_INCREMENT, DEFAULT CURRENT_TIMESTAMP.
     */
    public static function adapt(string $sql, Driver $driver): string
    {
        return match ($driver) {
            Driver::MySQL => $sql,
            Driver::PostgreSQL => strtr($sql, [
                'TINYINT(1)' => 'SMALLINT',
                'JSON' => 'JSONB',
            ]),
            Driver::SQLite => strtr($sql, [
                'TINYINT(1)' => 'INTEGER',
                'TIMESTAMP' => 'TEXT',
                'JSON' => 'TEXT',
                'DECIMAL(10,2)' => 'REAL',
            ]),
        };
    }
}
