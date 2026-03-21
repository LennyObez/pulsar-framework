<?php

declare(strict_types=1);

namespace Pulsar\Storage;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Storage adapter contract for object/file storage operations.
 * @api
 */
#[Api(since: '1.0.0')]
interface StorageAdapterInterface
{
    /**
     * Store content under the given key.
     *
     * @throws StorageException If the write operation fails
     */
    public function put(string $key, string $content, ?StorageMetadata $metadata = null): void;

    /**
     * Retrieve content by key.
     *
     * @throws StorageException If the object does not exist or read fails
     */
    #[NoDiscard]
    public function get(string $key): string;

    /**
     * Check if an object exists at the given key.
     */
    public function exists(string $key): bool;

    /**
     * Delete an object by key.
     *
     * @throws StorageException If the delete operation fails
     */
    public function delete(string $key): void;

    /**
     * List objects under the given prefix.
     *
     * @return list<StorageObject>
     */
    public function list(string $prefix = ''): array;

    /**
     * Generate a temporary URL for direct access.
     *
     * @return string|null URL string or null if not supported by the adapter
     */
    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): ?string;
}
