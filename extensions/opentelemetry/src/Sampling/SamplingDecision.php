<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Sampling;

use Pulsar\Api\Api;

/**
 * Immutable result of a sampling decision.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SamplingDecision
{
    public function __construct(
        public bool $sampled,
        public string $reason = '',
    ) {}
}
