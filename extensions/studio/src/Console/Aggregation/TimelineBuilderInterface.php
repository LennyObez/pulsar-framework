<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Aggregation;

use Pulsar\Api\Internal;

/**
 * Contract for building event timelines from raw event data.
 */
#[Internal]
interface TimelineBuilderInterface
{
    /**
     * Build a timeline from a list of raw events.
     *
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public function build(array $events): array;
}
