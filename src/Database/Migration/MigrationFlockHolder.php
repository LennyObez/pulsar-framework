<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Internal;

/**
 * Holds the file handle for SQLite flock-based advisory locking.
 *
 * Extracted to a separate class because {@see MigrationRunner} is `readonly`
 * and cannot hold mutable static or instance properties. This holder uses
 * a function-scoped static to store the resource handle between
 * acquire and release calls within the same process.
 */
#[Internal]
final class MigrationFlockHolder
{
    /** @var resource|null */
    private static mixed $handle = null;

    /**
     * Store the flock file handle.
     *
     * @param resource $handle
     */
    public static function set(mixed $handle): void
    {
        self::$handle = $handle;
    }

    /**
     * Retrieve the stored flock file handle.
     *
     * @return resource|null
     */
    public static function get(): mixed
    {
        return self::$handle;
    }

    /**
     * Clear the stored handle.
     */
    public static function clear(): void
    {
        self::$handle = null;
    }

    private function __construct() {}
}
