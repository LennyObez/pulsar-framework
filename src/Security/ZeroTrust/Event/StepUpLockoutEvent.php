<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Event;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Dispatched when an identity is locked out due to exceeding step-up attempt limits.
 *
 * This is a high-severity security event. Listeners should trigger alerts
 * and potentially escalate to security teams.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StepUpLockoutEvent
{
    /**
     * @param string $identityId Identity that has been locked out
     * @param int $attemptCount Total attempts before lockout
     * @param DateTimeImmutable $lockedUntil When the lockout expires
     */
    public function __construct(
        public string $identityId,
        public int $attemptCount,
        public DateTimeImmutable $lockedUntil,
    ) {}
}
