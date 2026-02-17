<?php

declare(strict_types=1);

namespace Pulsar\Extension\HealthStatus\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\HealthStatus\Domain\HealthSnapshot;
use Pulsar\Extension\HealthStatus\Domain\Incident;

/**
 * Detects incidents from health check snapshots.
 *
 * Implementations compare the current snapshot against recent history
 * to determine whether new incidents should be opened or existing
 * ones should be auto-resolved.
 */
#[Api(since: '1.0.0')]
interface IncidentDetectorInterface
{
    /**
     * Analyze the current snapshot and return any incidents to create or update.
     *
     * Returns new incidents (status=Open) when consecutive failure thresholds
     * are met, and resolved incidents (status=Resolved) when checks recover.
     *
     * @return list<Incident>
     */
    public function detect(HealthSnapshot $snapshot, HealthHistoryStoreInterface $store): array;
}
