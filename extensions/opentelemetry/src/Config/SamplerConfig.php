<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     type?: string,
     *     probability?: float|int,
     *     rate_per_second?: float|int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            type: SamplerType::tryFrom($data['type'] ?? '') ?? SamplerType::ParentBased,
            probability: (float) ($data['probability'] ?? 1.0),
            ratePerSecond: (float) ($data['rate_per_second'] ?? 100.0),
        );
    }
}
