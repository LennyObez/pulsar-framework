<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\ContinuousVerification;

use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Policy\PolicyEvaluationResult;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;

/**
 * Contract for continuous zero-trust verification during active sessions.
 *
 * Unlike initial authentication which happens once at login, continuous verification
 * re-evaluates the trust posture throughout the session lifecycle. Implementations
 * collect fresh signals, evaluate policy, and determine whether the session should
 * continue, require step-up authentication, or be terminated.
 */
#[Api(since: '1.0.0')]
interface ContinuousVerificationInterface
{
    /**
     * Re-evaluate the trust posture for the current context.
     *
     * Collects signals, builds a fresh ClaimSet, and evaluates it against
     * the policy engine for the given resource and action. Returns the
     * full evaluation result for the caller to act on.
     *
     * @param SignalContext $context Current request/session context
     * @param string $resource The resource being accessed
     * @param string $action The action being performed
     */
    public function verify(SignalContext $context, string $resource, string $action): PolicyEvaluationResult;
}
