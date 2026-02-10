<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Represents an API key for authenticating CMS content API requests.
 *
 * Keys are stored as SHA-256 hashes — raw key material is never persisted.
 */
#[Api(since: '1.0.0')]
final readonly class ApiKey
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId Optional tenant scope
     * @param string $name Human-readable label for the key
     * @param string $keyHash SHA-256 hash of the raw API key
     * @param string|null $lastUsedAt ISO 8601 timestamp of last usage
     * @param bool $isActive Whether the key is currently enabled
     * @param DateTimeImmutable $createdAt When the key was created
     * @param DateTimeImmutable|null $expiresAt Optional expiry (null = never expires)
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $name,
        public string $keyHash,
        public ?string $lastUsedAt,
        public bool $isActive,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $expiresAt,
    ) {}

    /**
     * Whether this key has passed its expiration date.
     */
    public function isExpired(): bool
    {
        return $this->expiresAt !== null && new DateTimeImmutable() >= $this->expiresAt;
    }
}
