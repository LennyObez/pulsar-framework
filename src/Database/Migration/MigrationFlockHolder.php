<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Internal;

/**
 * Holds the file handles for SQLite flock-based advisory locking.
 *
 * Extracted to a separate class because {@see MigrationRunner} is `readonly`
 * and cannot hold mutable static or instance properties. This holder uses
 * a process-scoped static map to store the resource handles between
 * acquire and release calls within the same process.
 *
 * Handles are keyed by their lock path so that two {@see MigrationRunner}
 * instances locking different SQLite databases (e.g. a framework and an
 * extension each owning a distinct migration table) do not overwrite or
 * release each other's handles.
 */
#[Internal]
final class MigrationFlockHolder
{
    /** @var array<string, resource> */
    private static array $handles = [];

    /**
     * Store the flock file handle for the given lock path.
     *
     * @param resource $handle
     */
    public static function set(string $lockPath, mixed $handle): void
    {
        self::$handles[$lockPath] = $handle;
    }

    /**
     * Retrieve the stored flock file handle for the given lock path.
     *
     * @return resource|null
     */
    public static function get(string $lockPath): mixed
    {
        return self::$handles[$lockPath] ?? null;
    }

    /**
     * Clear the stored handle for the given lock path.
     */
    public static function clear(string $lockPath): void
    {
        unset(self::$handles[$lockPath]);
    }
}
