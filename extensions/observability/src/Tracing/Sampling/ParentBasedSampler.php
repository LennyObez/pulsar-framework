<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tracing\Sampling;

use Override;
use Pulsar\Api\Api;
use Pulsar\Observability\Tracing\TraceContext;

/**
 * Sampler that respects the parent trace's sampling decision.
 *
 * Honors the W3C traceFlags sampling bit from the parent context:
 * - traceFlags sampled (bit 0 set) -> sampled
 * - traceFlags not sampled (bit 0 clear) -> dropped
 *
 * For explicit root span decisions (no parent), use shouldSampleRoot()
 * which delegates to the configurable root sampler.
 *
 * Root spans created via TraceContext::create() default to traceFlags=0x01
 * (sampled), ensuring they pass through shouldSample().
 */
#[Api(since: '1.0.0')]
final readonly class ParentBasedSampler implements SamplerInterface
{
    private SamplerInterface $rootSampler;

    public function __construct(?SamplerInterface $rootSampler = null)
    {
        $this->rootSampler = $rootSampler ?? new AlwaysSampler();
    }

    #[Override]
    public function shouldSample(TraceContext $context): SamplingDecision
    {
        // Honor the parent's sampling decision via traceFlags
        if ($context->isSampled()) {
            return new SamplingDecision(sampled: true, reason: 'parent_sampled');
        }

        return new SamplingDecision(sampled: false, reason: 'parent_not_sampled');
    }

    /**
     * Sample decision for root spans (no parent context).
     *
     * Delegates to the configured root sampler for fresh traces
     * where no parent sampling decision exists.
     */
    public function shouldSampleRoot(TraceContext $context): SamplingDecision
    {
        return $this->rootSampler->shouldSample($context);
    }
}
