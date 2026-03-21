<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tracing\Sampling;

use Override;
use Pulsar\Api\Api;
use Pulsar\Observability\Tracing\TraceContext;

/**
 * Sampler that never records traces.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class NeverSampler implements SamplerInterface
{
    #[Override]
    public function shouldSample(TraceContext $context): SamplingDecision
    {
        return new SamplingDecision(sampled: false, reason: 'never');
    }
}
