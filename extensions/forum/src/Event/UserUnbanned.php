<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a user's forum ban is lifted.
 */
#[Api(since: '1.0.0')]
final readonly class UserUnbanned
{
    public function __construct(
        public string $userId,
        public string $unbannedBy,
        public ?string $tenantId = null,
    ) {}
}
