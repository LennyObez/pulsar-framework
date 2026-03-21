<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Policy;

use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;

use function sprintf;

/**
 * Specification for a required claim in a policy rule.
 *
 * Defines what claim must be present, with what minimum confidence,
 * and from which sources it is accepted. Used by the policy engine
 * to evaluate whether a ClaimSet satisfies a rule's requirements.
 */
#[Api(since: '1.0.0')]
final readonly class ClaimRequirement
{
    /**
     * @param string $claimName Required claim identifier (e.g., "device.registered")
     * @param float $minConfidence Minimum acceptable confidence (0.0-1.0)
     * @param list<ClaimSource> $allowedSources Sources accepted for this claim; empty = all sources accepted
     */
    public function __construct(
        public string $claimName,
        public float $minConfidence = 0.0,
        public array $allowedSources = [],
    ) {
        if ($this->claimName === '') {
            throw new InvalidArgumentException('Claim requirement name must not be empty');
        }

        if ($this->minConfidence < 0.0 || $this->minConfidence > 1.0) {
            throw new InvalidArgumentException(
                sprintf('Claim requirement minConfidence must be between 0.0 and 1.0, got %f', $this->minConfidence),
            );
        }
    }
}
