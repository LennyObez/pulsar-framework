<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Policy\Internal;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Event\PolicyDecisionEvent;
use Pulsar\Security\ZeroTrust\Policy\ClaimRequirement;
use Pulsar\Security\ZeroTrust\Policy\PolicyDecision;
use Pulsar\Security\ZeroTrust\Policy\PolicyEngineInterface;
use Pulsar\Security\ZeroTrust\Policy\PolicyEvaluationResult;
use Pulsar\Security\ZeroTrust\Policy\PolicyRule;

use function array_filter;
use function array_values;
use function fnmatch;
use function in_array;
use function usort;

/**
 * Zero-trust policy engine that evaluates claim sets against configured rules.
 *
 * Implements deny-by-default semantics. Rules are matched by resource pattern
 * (glob-style) and action (exact or wildcard). For each matching rule, all
 * claim requirements must be satisfied including minimum confidence and
 * allowed source constraints.
 *
 * Rules are evaluated in priority order (highest first). The first matching
 * rule whose requirements are fully satisfied determines the decision.
 */
#[Internal]
final readonly class PolicyEngine implements PolicyEngineInterface
{
    /** @var list<PolicyRule> Rules sorted by priority (highest first) */
    private array $sortedRules;

    /**
     * @param list<PolicyRule> $rules Policy rules to evaluate against
     */
    public function __construct(
        array $rules,
        private EventDispatcherInterface $eventDispatcher,
        private AuditLoggerInterface $auditLogger,
        private string $identityId = '',
        private string $sessionId = '',
    ) {
        $sorted = $rules;
        usort($sorted, static fn(PolicyRule $a, PolicyRule $b): int => $b->priority <=> $a->priority);
        $this->sortedRules = $sorted;
    }

    #[Override]
    public function evaluate(ClaimSet $claims, string $resource, string $action): PolicyEvaluationResult
    {
        $matchingRules = $this->findMatchingRules($resource, $action);

        if ($matchingRules === []) {
            $result = new PolicyEvaluationResult(
                decision: PolicyDecision::Deny,
                matchedRules: [],
                missingClaims: [],
                claimSnapshot: $claims,
                resource: $resource,
                action: $action,
            );

            $this->dispatchAndLog($result);

            return $result;
        }

        foreach ($matchingRules as $rule) {
            $missingClaims = $this->findUnsatisfiedRequirements($rule, $claims);

            if ($missingClaims === []) {
                $result = new PolicyEvaluationResult(
                    decision: $rule->onMatch,
                    matchedRules: $matchingRules,
                    missingClaims: [],
                    claimSnapshot: $claims,
                    resource: $resource,
                    action: $action,
                );

                $this->dispatchAndLog($result);

                return $result;
            }
        }

        // No rule had all requirements satisfied - use the highest-priority rule's onNoMatch
        $highestPriorityRule = $matchingRules[0];
        $missingClaims = $this->findUnsatisfiedRequirements($highestPriorityRule, $claims);

        $result = new PolicyEvaluationResult(
            decision: $highestPriorityRule->onNoMatch,
            matchedRules: $matchingRules,
            missingClaims: $missingClaims,
            claimSnapshot: $claims,
            resource: $resource,
            action: $action,
        );

        $this->dispatchAndLog($result);

        return $result;
    }

    /**
     * Find all rules whose resource pattern and action match the request.
     *
     * @return list<PolicyRule>
     */
    private function findMatchingRules(string $resource, string $action): array
    {
        return array_values(array_filter(
            $this->sortedRules,
            static fn(PolicyRule $rule): bool => fnmatch($rule->resourcePattern, $resource)
                && ($rule->action === '*' || $rule->action === $action),
        ));
    }

    /**
     * Find requirements that the claim set does not satisfy.
     *
     * A requirement is satisfied when:
     * 1. A claim with the required name exists in the set
     * 2. The claim's confidence >= the requirement's minimum confidence
     * 3. The claim's source is in the requirement's allowed sources (if specified)
     *
     * @return list<ClaimRequirement>
     */
    private function findUnsatisfiedRequirements(PolicyRule $rule, ClaimSet $claims): array
    {
        $unsatisfied = [];

        foreach ($rule->requirements as $requirement) {
            if (!$this->isRequirementSatisfied($requirement, $claims)) {
                $unsatisfied[] = $requirement;
            }
        }

        return $unsatisfied;
    }

    private function isRequirementSatisfied(ClaimRequirement $requirement, ClaimSet $claims): bool
    {
        $matchingClaims = $claims->getByName($requirement->claimName);

        if ($matchingClaims === []) {
            return false;
        }

        return array_any($matchingClaims, fn(Claim $claim): bool => $this->claimMeetsRequirement($claim, $requirement));
    }

    private function claimMeetsRequirement(Claim $claim, ClaimRequirement $requirement): bool
    {
        if ($claim->confidence < $requirement->minConfidence) {
            return false;
        }

        if ($requirement->allowedSources !== [] && !$this->isSourceAllowed($claim->source, $requirement->allowedSources)) {
            return false;
        }

        return true;
    }

    /**
     * @param list<ClaimSource> $allowedSources
     */
    private function isSourceAllowed(ClaimSource $source, array $allowedSources): bool
    {
        return in_array($source, $allowedSources, true);
    }

    private function dispatchAndLog(PolicyEvaluationResult $result): void
    {
        $this->eventDispatcher->dispatch(new PolicyDecisionEvent(
            result: $result,
            identityId: $this->identityId,
            sessionId: $this->sessionId,
        ));

        $outcome = match ($result->decision) {
            PolicyDecision::Grant => AuditOutcome::Success,
            PolicyDecision::Deny => AuditOutcome::Denied,
            PolicyDecision::StepUp => AuditOutcome::Failure,
        };

        $ruleNames = [];
        foreach ($result->matchedRules as $rule) {
            $ruleNames[] = $rule->name;
        }

        $missingClaimNames = [];
        foreach ($result->missingClaims as $requirement) {
            $missingClaimNames[] = $requirement->claimName;
        }

        $this->auditLogger->log(
            event: AuditEvent::Authorization,
            outcome: $outcome,
            actor: null,
            action: 'policy.evaluate',
            resource: $result->resource,
            metadata: [
                'decision' => $result->decision->value,
                'action' => $result->action,
                'matched_rules' => $ruleNames,
                'missing_claims' => $missingClaimNames,
                'claim_count' => $result->claimSnapshot->count(),
                'identity_id' => $this->identityId,
                'session_id' => $this->sessionId,
            ],
        );
    }
}
