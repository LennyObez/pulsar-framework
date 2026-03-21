<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Policy;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;

/**
 * Result of a policy engine evaluation.
 *
 * Contains the decision, the rules that were evaluated, which claims were
 * missing or insufficient, and a snapshot of the claims at evaluation time
 * for audit purposes.
 */
#[Api(since: '1.0.0')]
final readonly class PolicyEvaluationResult
{
    /**
     * @param PolicyDecision $decision The final policy decision
     * @param list<PolicyRule> $matchedRules Rules whose resource/action patterns matched
     * @param list<ClaimRequirement> $missingClaims Requirements that were not satisfied
     * @param ClaimSet $claimSnapshot Claims at evaluation time (for audit trail)
     * @param string $resource The resource that was evaluated
     * @param string $action The action that was evaluated
     */
    public function __construct(
        public PolicyDecision $decision,
        public array $matchedRules,
        public array $missingClaims,
        public ClaimSet $claimSnapshot,
        public string $resource,
        public string $action,
    ) {}

    /**
     * Whether the evaluation resulted in access being granted.
     */
    #[NoDiscard]
    public function isGranted(): bool
    {
        return $this->decision === PolicyDecision::Grant;
    }

    /**
     * Whether the evaluation requires step-up authentication.
     */
    #[NoDiscard]
    public function requiresStepUp(): bool
    {
        return $this->decision === PolicyDecision::StepUp;
    }

    /**
     * Whether the evaluation resulted in access being denied.
     */
    #[NoDiscard]
    public function isDenied(): bool
    {
        return $this->decision === PolicyDecision::Deny;
    }
}
