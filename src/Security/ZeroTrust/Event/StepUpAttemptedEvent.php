<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Event;

use Pulsar\Api\Api;

/**
 * Dispatched when a step-up authentication is attempted.
 *
 * Listeners can use this for monitoring step-up frequency,
 * detecting brute-force patterns, and audit logging.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class StepUpAttemptedEvent
{
    /**
     * @param string $identityId Identity attempting step-up
     * @param string $resource Resource that triggered the step-up requirement
     * @param int $attemptNumber Current attempt number in the window
     * @param bool $success Whether the step-up attempt succeeded
     */
    public function __construct(
        public string $identityId,
        public string $resource,
        public int $attemptNumber,
        public bool $success,
    ) {}
}
