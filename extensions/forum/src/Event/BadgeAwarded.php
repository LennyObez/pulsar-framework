<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a badge is awarded to a user.
 */
#[Api(since: '1.0.0')]
final readonly class BadgeAwarded
{
    public function __construct(
        public string $badgeId,
        public string $userId,
        public string $badge,
        public ?string $tenantId = null,
    ) {}
}
