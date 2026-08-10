<?php

declare(strict_types=1);

namespace Pulsar\Database;

use Pulsar\Api\Api;
use Pulsar\Database\Exception\InvalidDsnComponentException;

use function getcwd;
use function preg_match;
use function sprintf;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const PHP_OS_FAMILY;

/**
 * Supported database drivers.
 * @api
 */
#[Api(since: '1.0.0')]
enum Driver: string
{
    case MySQL = 'mysql';
    case PostgreSQL = 'pgsql';
    case SQLite = 'sqlite';

    /**
     * Build a PDO DSN string for the given connection parameters.
     *
     * Each component is validated against the structural delimiters of the
     * PDO DSN format (`;`, `=`, NUL, CR, LF) before substitution so an
     * environment variable that contains one of those bytes cannot override
     * a later parameter (`dbname=`, `unix_socket=`, `charset=`). This is
     * the DSN injection guard.
     *
     * @throws InvalidDsnComponentException
     */
    public function buildDsn(string $host, int $port, string $database, ?string $charset = null): string
    {
        self::assertSafeDsnComponent('host', $host);

        // SQLite database is a path: it can legitimately contain `:`, `/`,
        // and (on Windows) `\`, so only the structural DSN delimiters are
        // forbidden. The MySQL/PostgreSQL `database` is a schema name; the
        // same forbidden-byte check applies.
        self::assertSafeDsnComponent('database', $database);

        if ($charset !== null) {
            self::assertSafeDsnComponent('charset', $charset);
        }

        return match ($this) {
            self::MySQL => sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $database)
                . ($charset !== null ? sprintf(';charset=%s', $charset) : ''),
            self::PostgreSQL => sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database),
            self::SQLite => sprintf('sqlite:%s', self::resolveSqlitePath($database)),
        };
    }

    /**
     * Refuse any DSN component containing the structural delimiters of the
     * PDO DSN grammar. The grammar treats `;` as a parameter separator, `=`
     * as a key/value separator, and NUL/CR/LF as line / string terminators
     * — letting any of those reach the final DSN string is a injection
     * vector identical to SQL/HTTP-header smuggling.
     *
     * @throws InvalidDsnComponentException
     */
    private static function assertSafeDsnComponent(string $component, string $value): void
    {
        if (preg_match('/[\x00\x0A\x0D;=]/', $value) === 1) {
            throw InvalidDsnComponentException::forbiddenByte($component, $value);
        }
    }

    /**
     * Resolve a SQLite database path to an absolute path.
     *
     * Special values (:memory:) are returned as-is. Relative paths are
     * resolved against the current working directory. For production use,
     * prefer passing absolute paths via ConnectionConfig to avoid
     * working-directory ambiguity in symlinked projects.
     *
     * This method does NOT confine absolute paths to a base
     * directory. A `DB_DATABASE` env var pointing to `/etc/passwd`
     * would resolve there. Multi-tenant deployments that pass user-
     * derived strings into ConnectionConfig MUST validate the path
     * before construction (e.g. via {@see Pulsar\Filesystem\SafePath}
     * with a tenant-rooted boundary). The framework cannot impose a
     * base dir at this layer because legitimate single-tenant
     * deployments place their SQLite file at arbitrary FS locations
     * (e.g. `/var/lib/pulsar/db.sqlite`); enforcing a fixed root
     * here would break them.
     *
     * Defence against the env-var injection path itself is at
     * {@see assertSafeDsnComponent()}, which rejects `;`,
     * `=`, NUL, CR, LF in the database string before it reaches
     * the DSN. The remaining attack surface is operator-controlled.
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
}
