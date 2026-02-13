<?php

declare(strict_types=1);

namespace Pulsar\Database;

use Pulsar\Api\Api;

use function getcwd;
use function sprintf;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const PHP_OS_FAMILY;

/**
 * Supported database drivers.
 */
#[Api(since: '1.0.0')]
enum Driver: string
{
    case MySQL = 'mysql';
    case PostgreSQL = 'pgsql';
    case SQLite = 'sqlite';

    /**
     * Build a PDO DSN string for the given connection parameters.
     */
    public function buildDsn(string $host, int $port, string $database, ?string $charset = null): string
    {
        return match ($this) {
            self::MySQL => sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $database)
                . ($charset !== null ? sprintf(';charset=%s', $charset) : ''),
            self::PostgreSQL => sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database),
            self::SQLite => sprintf('sqlite:%s', self::resolveSqlitePath($database)),
        };
    }

    /**
     * Resolve a SQLite database path to an absolute path.
     *
     * Special values (:memory:) are returned as-is. Relative paths are
     * resolved against the current working directory. For production use,
     * prefer passing absolute paths via ConnectionConfig to avoid
     * working-directory ambiguity in symlinked projects.
     */
    private static function resolveSqlitePath(string $database): string
    {
        if ($database === ':memory:' || $database === '') {
            return $database;
        }

        // Already absolute (Unix or Windows)
        if (str_starts_with($database, '/') || (PHP_OS_FAMILY === 'Windows' && isset($database[1]) && $database[1] === ':')) {
            return $database;
        }

        // Relative path: resolve against cwd
        $cwd = getcwd();
        if ($cwd === false) {
            return $database;
        }

        return $cwd . DIRECTORY_SEPARATOR . $database;
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
            self::MySQL, self::PostgreSQL, self::SQLite => true,
        };
    }
}
