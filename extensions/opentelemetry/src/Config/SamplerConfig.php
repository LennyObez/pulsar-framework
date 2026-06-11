<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
            type: SamplerType::tryFrom(Coerce::string($data['type'] ?? null)) ?? SamplerType::ParentBased,
            probability: Coerce::float($data['probability'] ?? null, 1.0),
            ratePerSecond: Coerce::float($data['rate_per_second'] ?? null, 100.0),
        );
    }
}
