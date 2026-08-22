<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Policy;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Support\Coerce;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Specification for a required claim in a policy rule.
 *
 * Defines what claim must be present, with what minimum confidence,
 * and from which sources it is accepted. Used by the policy engine
 * to evaluate whether a ClaimSet satisfies a rule's requirements.
 * @api
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

    /**
     * Build from a config array (see config/security.php `zero_trust.rules`).
     * Reads the `claim`, `min_confidence`, and `allowed_sources` keys, narrowing
     * each via {@see Coerce} / runtime checks at this validator boundary.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $allowedSources = [];
        /** @var mixed $rawSources */
        $rawSources = $data['allowed_sources'] ?? [];

        if (is_array($rawSources)) {
            /** @var mixed $rawSource */
            foreach ($rawSources as $rawSource) {
                if (is_string($rawSource)) {
                    $source = ClaimSource::tryFrom($rawSource);

                    if ($source !== null) {
                        $allowedSources[] = $source;
                    }
                }
            }
        }

        return new self(
            claimName: Coerce::string($data['claim'] ?? null, ''),
            minConfidence: Coerce::float($data['min_confidence'] ?? null, 0.0),
            allowedSources: $allowedSources,
        );
    }
}
