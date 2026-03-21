<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Policy;

use Pulsar\Api\Api;

/**
 * A single policy rule matching a resource pattern and action to claim requirements.
 *
 * When a request matches the resource pattern and action, the policy engine checks
 * whether the ClaimSet satisfies all requirements. The decision fields control
 * what happens when requirements are fully met, partially met, or unmet.
 */
#[Api(since: '1.0.0')]
final readonly class PolicyRule
{
    /**
     * @param string $name Human-readable rule identifier for logging and debugging
     * @param string $resourcePattern Glob-style pattern matching the target resource (e.g., "/admin/*")
     * @param string $action Action being performed (e.g., "read", "write", "delete", "*" for any)
     * @param list<ClaimRequirement> $requirements Claims that must be present and satisfied
     * @param PolicyDecision $onMatch Decision when all requirements are satisfied
     * @param PolicyDecision $onNoMatch Decision when requirements are not satisfied
     * @param int $priority Higher priority rules are evaluated first (default 0)
     */
    public function __construct(
        public string $name,
        public string $resourcePattern,
        public string $action,
        public array $requirements,
        public PolicyDecision $onMatch = PolicyDecision::Grant,
        public PolicyDecision $onNoMatch = PolicyDecision::Deny,
        public int $priority = 0,
    ) {}
}
