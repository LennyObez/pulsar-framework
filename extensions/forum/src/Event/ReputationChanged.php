<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a user's reputation score changes.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ReputationChanged
{
    public function __construct(
        public string $userId,
        public int $previousScore,
        public int $newScore,
        public int $delta,
        public string $reason,
        public ?string $tenantId = null,
    ) {}
}
