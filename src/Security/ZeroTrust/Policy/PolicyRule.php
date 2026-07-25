<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Policy;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function array_filter;
use function array_map;
use function array_values;
use function is_array;

/**
 * A single policy rule matching a resource pattern and action to claim requirements.
 *
 * When a request matches the resource pattern and action, the policy engine checks
 * whether the ClaimSet satisfies all requirements. The decision fields control
 * what happens when requirements are fully met, partially met, or unmet.
 * @api
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

    /**
     * Build from a config array (see config/security.php `zero_trust.rules`).
     * Unknown decision strings fall back to the deny-by-default posture.
     *
     * @param array{
     *     name?: string,
     *     resource_pattern?: string,
     *     action?: string,
     *     requirements?: mixed,
     *     on_match?: string,
     *     on_no_match?: string,
     *     priority?: int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var mixed $rawRequirements */
        $rawRequirements = $data['requirements'] ?? [];
        /** @var list<array<string, mixed>> $requirementArrays */
        $requirementArrays = is_array($rawRequirements)
            ? array_values(array_filter($rawRequirements, is_array(...)))
            : [];

        return new self(
            name: Coerce::string($data['name'] ?? null, ''),
            resourcePattern: Coerce::string($data['resource_pattern'] ?? null, '*'),
            action: Coerce::string($data['action'] ?? null, '*'),
            requirements: array_map(ClaimRequirement::fromArray(...), $requirementArrays),
            onMatch: PolicyDecision::tryFrom(Coerce::string($data['on_match'] ?? null, 'grant')) ?? PolicyDecision::Grant,
            onNoMatch: PolicyDecision::tryFrom(Coerce::string($data['on_no_match'] ?? null, 'deny')) ?? PolicyDecision::Deny,
            priority: Coerce::strictInt($data['priority'] ?? null, 0),
        );
    }
}
