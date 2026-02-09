<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Migration;

use Pulsar\Api\Api;
use Pulsar\Database\Driver;

/**
 * Lightweight DDL adapter for CMS migrations.
 *
 * Handles common PostgreSQL type and default substitutions for SQLite and MySQL.
 * Structural differences (indexes, CHECK constraints) are handled per-migration
 * with driver conditionals.
 */
#[Api(since: '1.0.0')]
final class CmsDdl
{
    /**
     * Adapt PostgreSQL DDL to the target driver.
     *
     * Handles: TIMESTAMPTZ, JSONB, TSVECTOR, DOUBLE PRECISION, DEFAULT NOW().
     * Does NOT handle structural differences (indexes, CHECK constraints) — those
     * are handled per-migration with driver conditionals.
     */
    public static function adapt(string $sql, Driver $driver): string
    {
        // Universal: NOW() → CURRENT_TIMESTAMP (ISO SQL, all drivers)
        $sql = str_replace('DEFAULT NOW()', 'DEFAULT CURRENT_TIMESTAMP', $sql);

        return match ($driver) {
            Driver::PostgreSQL => $sql,
            Driver::SQLite => strtr($sql, [
                'TIMESTAMPTZ' => 'TEXT',
                'JSONB' => 'TEXT',
                'TSVECTOR' => 'TEXT',
                'DOUBLE PRECISION' => 'REAL',
            ]),
            Driver::MySQL => strtr($sql, [
                'TIMESTAMPTZ' => 'DATETIME',
                'JSONB' => 'JSON',
                'TSVECTOR' => 'TEXT',
                'DOUBLE PRECISION' => 'DOUBLE',
            ]),
        };
    }
}
