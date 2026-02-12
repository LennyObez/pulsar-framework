<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Sampling;

use Override;
use Pulsar\Api\Api;
use Pulsar\Observability\Tracing\TraceContext;

/**
 * Sampler that always records traces.
 */
#[Api(since: '1.0.0')]
final readonly class AlwaysSampler implements SamplerInterface
{
    #[Override]
    public function shouldSample(TraceContext $context): SamplingDecision
    {
        return new SamplingDecision(sampled: true, reason: 'always');
    }
}
