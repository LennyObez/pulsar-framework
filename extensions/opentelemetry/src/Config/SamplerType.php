<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Config;

use Pulsar\Api\Api;

/**
 * Trace sampler strategy type.
 */
#[Api(since: '1.0.0')]
enum SamplerType: string
{
    case Always = 'always';
    case Never = 'never';
    case Probability = 'probability';
    case RateLimited = 'rate_limited';
    case ParentBased = 'parent_based';
}
