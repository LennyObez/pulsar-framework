<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Sampling;

use Pulsar\Api\Api;
use Pulsar\Observability\Tracing\TraceContext;

/**
 * Decides whether a trace should be sampled (recorded and exported).
 * @api
 */
#[Api(since: '1.0.0')]
interface SamplerInterface
{
    public function shouldSample(TraceContext $context): SamplingDecision;
}
