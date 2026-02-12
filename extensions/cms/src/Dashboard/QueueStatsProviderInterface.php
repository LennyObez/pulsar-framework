<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Dashboard;

use Pulsar\Api\Api;

/**
 * Provides queue statistics for dashboard display.
 *
 * Abstracts the underlying queue monitor to avoid coupling dashboard
 * widgets to concrete final classes.
 */
#[Api(since: '1.0.0')]
interface QueueStatsProviderInterface
{
    /**
     * Get the number of pending jobs for a named queue.
     */
    public function getPendingCount(string $queue): int;

    /**
     * Get the number of failed jobs for a named queue.
     */
    public function getFailedCount(string $queue): int;
}
