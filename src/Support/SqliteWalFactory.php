<?php

declare(strict_types=1);

namespace Pulsar\Support;

use function dirname;
use function is_dir;
use function mkdir;

use PDO;
use Pulsar\Api\Api;

/**
 * Factory for creating SQLite PDO connections with WAL journaling.
 *
 * Provides a standard setup for SQLite databases: WAL mode for
 * concurrent read access, a 5-second busy timeout, and automatic
 * directory creation.
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
        $dir = dirname($storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }

        $db = new PDO('sqlite:' . $storagePath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA busy_timeout=5000');
        $db->exec($schema);

        return $db;
    }
}
