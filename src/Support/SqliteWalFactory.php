<?php

declare(strict_types=1);

namespace Pulsar\Support;

use InvalidArgumentException;
use PDO;
use Pulsar\Api\Api;

use function dirname;
use function is_dir;
use function mkdir;
use function preg_match;
use function sprintf;

/**
 * Factory for creating SQLite PDO connections with WAL journaling.
 *
 * Provides a standard setup for SQLite databases: WAL mode for
 * concurrent read access, a 5-second busy timeout, and automatic
 * directory creation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SqliteWalFactory
{
    /**
     * Create a SQLite PDO connection with WAL mode and standard pragmas.
     *
     * @param string $storagePath Path to the SQLite database file
     * @param string $schema SQL schema to execute (CREATE TABLE IF NOT EXISTS ...)
     */
    public static function create(string $storagePath, string $schema): PDO
    {
        self::assertDdl($schema);

        $dir = dirname($storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        $pdo = new PDO('sqlite:' . $storagePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec($schema);

        return $pdo;
    }

    /**
     * Validate that the schema string starts with a DDL statement.
     */
    private static function assertDdl(string $schema): void
    {
        if (preg_match('/\A\s*(CREATE|ALTER|DROP)\b/i', $schema) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Schema must start with a DDL statement (CREATE, ALTER, or DROP). Got: "%s"',
                substr($schema, 0, 40),
            ));
        }
    }
}
