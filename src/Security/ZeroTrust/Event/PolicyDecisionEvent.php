<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Event;

use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Policy\PolicyEvaluationResult;

/**
 * Dispatched after every policy evaluation.
 *
 * Listeners can use this event for audit logging, metrics collection,
 * and anomaly detection based on access patterns.
 */
#[Api(since: '1.0.0')]
readonly class PolicyDecisionEvent
{
    public function __construct(
        public PolicyEvaluationResult $result,
        public string $identityId,
        public string $sessionId,
    ) {}
}
