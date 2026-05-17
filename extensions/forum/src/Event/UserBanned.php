<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Dispatched when a user is banned from the forum.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class UserBanned
{
    public function __construct(
        public string $userId,
        public string $bannedBy,
        public string $reason,
        public ?DateTimeImmutable $expiresAt = null,
        public ?string $tenantId = null,
    ) {}
}
