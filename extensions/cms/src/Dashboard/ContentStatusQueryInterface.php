<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Dashboard;

use Pulsar\Api\Api;

/**
 * Read-only query interface for content status counts used by the dashboard.
 *
 * Implementations should use optimized COUNT queries rather than loading entities.
 *
 * @psalm-api Public binding contract; consumed by dashboard widgets.
 */
#[Api(since: '1.0.0')]
interface ContentStatusQueryInterface
{
    /**
     * Count content items grouped by publishing status.
     *
     * @return array<string, int> Map of status value => count (e.g., ['draft' => 12, 'published' => 45])
     */
    public function countByStatus(?string $tenantId = null): array;

    /**
     * Get daily content creation counts for sparkline trend visualization.
     *
     * @return list<array{date: string, count: int}> Last N days of content creation counts
     */
    public function getDailyCreationTrend(int $days = 14, ?string $tenantId = null): array;
}
