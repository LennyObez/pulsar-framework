<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Event;

use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;

/**
 * Dispatched when a signal retention policy is modified.
 *
 * Compliance-relevant event: retention policy changes must be logged
 * for regulatory audit trails (GDPR, HIPAA).
 */
#[Api(since: '1.0.0')]
final readonly class SignalRetentionPolicyChangedEvent
{
    /**
     * @param ClaimSource $source Signal source whose retention policy changed
     * @param int $previousRetentionSeconds Previous retention duration
     * @param int $newRetentionSeconds New retention duration
     * @param string $changedBy Identity that made the change
     * @param string $reason Justification for the change
     */
    public function __construct(
        public ClaimSource $source,
        public int $previousRetentionSeconds,
        public int $newRetentionSeconds,
        public string $changedBy,
        public string $reason = '',
    ) {}
}
