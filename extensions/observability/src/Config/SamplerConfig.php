<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_float;
use function is_int;
use function is_string;

/**
 * Trace sampler configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SamplerConfig
{
    public function __construct(
        public SamplerType $type = SamplerType::ParentBased,
        public float $probability = 1.0,
        public float $ratePerSecond = 100.0,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $rawType = $data['type'] ?? 'parent_based';
        $type = is_string($rawType)
            ? (SamplerType::tryFrom($rawType) ?? SamplerType::ParentBased)
            : SamplerType::ParentBased;

        $rawProbability = $data['probability'] ?? 1.0;
        $probability = is_float($rawProbability) || is_int($rawProbability)
            ? (float) $rawProbability
            : 1.0;

        $rawRate = $data['rate_per_second'] ?? 100.0;
        $ratePerSecond = is_float($rawRate) || is_int($rawRate)
            ? (float) $rawRate
            : 100.0;

        return new self(
            type: $type,
            probability: $probability,
            ratePerSecond: $ratePerSecond,
        );
    }
}
