<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tracing\Sampling;

use Override;
use Pulsar\Api\Api;
use Pulsar\Observability\Tracing\TraceContext;
use Random\Engine\Secure;
use Random\Randomizer;

use function sprintf;

/**
 * Sampler that records traces with a given probability between 0.0 and 1.0.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ProbabilitySampler implements SamplerInterface
{
    private Randomizer $randomizer;
    private string $reason;

    public function __construct(
        private float $probability,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
        $this->reason = sprintf('probability(%s)', $this->probability);
    }

    #[Override]
    public function shouldSample(TraceContext $context): SamplingDecision
    {
        $sampled = $this->randomizer->getFloat(0.0, 1.0) < $this->probability;

        return new SamplingDecision(
            sampled: $sampled,
            reason: $this->reason,
        );
    }
}
