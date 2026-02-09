<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Repository interface for API key persistence and lookup.
 */
#[Api(since: '1.0.0')]
interface ApiKeyRepositoryInterface
{
    /**
     * Find an active API key by its SHA-256 hash.
     */
    public function findByKeyHash(string $keyHash): ?ApiKey;

    /**
     * Persist an API key (insert or update on conflict).
     */
    public function save(ApiKey $key): void;

    /**
     * Record the last-used timestamp for a key.
     */
    public function recordUsage(string $id): void;
}
