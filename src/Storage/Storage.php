<?php

declare(strict_types=1);

namespace Pulsar\Storage;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Static facade for storage operations.
 *
 * Provides a convenient shorthand for accessing storage disks:
 *
 *     Storage::disk('local')->put('file.txt', $content);
 *     Storage::put('file.txt', $content); // uses default disk
 *
 * Must be initialized with a StorageManager via Storage::bind() during
 * application bootstrap (handled by StorageWiring).
 */
#[Api(since: '1.0.0')]
final class Storage
{
    private static ?StorageManager $manager = null;

    /**
     * Bind the storage manager instance.
     *
     * Called once during bootstrap by StorageWiring. Not intended for
     * application-level use.
     */
    public static function bind(StorageManager $manager): void
    {
        self::$manager = $manager;
    }

    /**
     * Get a storage adapter for a specific disk.
     *
     * @throws StorageException If the disk is not configured or no manager is bound
     */
    #[NoDiscard]
    public static function disk(?string $name = null): StorageAdapterInterface
    {
        return self::manager()->disk($name);
    }

    /**
     * Store content on the default disk.
     *
     * @throws StorageException If the write fails
     */
    public static function put(string $key, string $content, ?StorageMetadata $metadata = null): void
    {
        self::manager()->disk()->put($key, $content, $metadata);
    }

    /**
     * Retrieve content from the default disk.
     *
     * @throws StorageException If the object does not exist
     */
    #[NoDiscard]
    public static function get(string $key): string
    {
        return self::manager()->disk()->get($key);
    }

    /**
     * Check if a key exists on the default disk.
     */
    public static function exists(string $key): bool
    {
        return self::manager()->disk()->exists($key);
    }

    /**
     * Delete a key from the default disk.
     *
     * @throws StorageException If the delete fails
     */
    public static function delete(string $key): void
    {
        self::manager()->disk()->delete($key);
    }

    /**
     * List objects on the default disk.
     *
     * @return list<StorageObject>
     */
    public static function list(string $prefix = ''): array
    {
        return self::manager()->disk()->list($prefix);
    }

    /**
     * Generate a temporary URL for an object on the default disk.
     */
    public static function temporaryUrl(string $key, int $expiresInSeconds = 3600): ?string
    {
        return self::manager()->disk()->temporaryUrl($key, $expiresInSeconds);
    }

    /**
     * Reset the facade (for testing only).
     */
    public static function reset(): void
    {
        self::$manager = null;
    }

    private static function manager(): StorageManager
    {
        if (self::$manager === null) {
            throw StorageException::connectionFailed('Storage facade has not been initialized. Call Storage::bind() or ensure StorageWiring runs.');
        }

        return self::$manager;
    }
}
