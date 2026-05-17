<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * A single step in a conversion funnel.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FunnelStep
{
    /**
     * @param int $position Step position (1-based)
     * @param string $name Human-readable step name
     * @param FunnelStepType $type How to match this step
     * @param string $value The pattern or event name to match
     */
    public function __construct(
        public int $position,
        public string $name,
        public FunnelStepType $type,
        public string $value,
    ) {}
}
