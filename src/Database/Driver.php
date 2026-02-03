<?php

declare(strict_types=1);

namespace Pulsar\Database;

use function sprintf;

/**
 * Supported database drivers.
 */
enum Driver: string
{
    case MySQL = 'mysql';
    case PostgreSQL = 'pgsql';
    case SQLite = 'sqlite';

    /**
     * Build a PDO DSN string for the given connection parameters.
     */
    public function buildDsn(string $host, int $port, string $database): string
    {
        return match ($this) {
            self::MySQL => sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $database),
            self::PostgreSQL => sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database),
            self::SQLite => sprintf('sqlite:%s', $database),
        };
    }

    /**
     * Get the default port for this driver.
     */
    public function defaultPort(): int
    {
        return match ($this) {
            self::MySQL => 3306,
            self::PostgreSQL => 5432,
            self::SQLite => 0,
        };
    }

    /**
     * Whether this driver supports savepoints for nested transactions.
     */
    public function supportsSavepoints(): bool
    {
        return match ($this) {
            self::MySQL, self::PostgreSQL => true,
            self::SQLite => true,
        };
    }
}
