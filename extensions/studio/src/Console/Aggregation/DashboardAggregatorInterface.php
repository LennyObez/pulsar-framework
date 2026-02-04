<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Aggregation;

use Pulsar\Api\Internal;

/**
 * Contract for aggregating dashboard metrics from the Studio event store.
 */
#[Internal]
interface DashboardAggregatorInterface
{
    /**
     * Aggregate all dashboard metrics into a single array.
     *
     * @return array<string, mixed>
     */
    public function aggregate(): array;
}
