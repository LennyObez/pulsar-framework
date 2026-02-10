<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Workflow;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Pessimistic lease-based lock to prevent simultaneous content edits.
 *
 * Locks auto-expire after a configurable TTL (default 30 minutes)
 * and are refreshed via periodic heartbeat while the editor is open.
 */
#[Api(since: '1.0.0')]
final readonly class ContentLock
{
    /**
     * @param string $contentId UUIDv7 PK (one lock per content item)
     * @param string $lockedBy UUIDv7 FK users
     * @param DateTimeImmutable $lockedAt When the lock was acquired
     * @param DateTimeImmutable $expiresAt When the lock automatically expires
     * @param string|null $locale BCP 47 locale code, null = locked for all locales
     */
    public function __construct(
        public string $contentId,
        public string $lockedBy,
        public DateTimeImmutable $lockedAt,
        public DateTimeImmutable $expiresAt,
        public ?string $locale,
    ) {}

    /**
     * Whether the lock has passed its expiration time.
     */
    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTimeImmutable();
    }
}
