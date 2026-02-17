<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;

/**
 * A single step in a user navigation flow path.
 */
#[Api(since: '1.0.0')]
final readonly class FlowStep
{
    /**
     * @param string $source Source page pathname
     * @param string $target Target page pathname
     * @param int $visitors Number of visitors who took this path
     * @param int $depth Step depth in the flow (0 = entry)
     */
    public function __construct(
        public string $source,
        public string $target,
        public int $visitors,
        public int $depth = 0,
    ) {}
}
