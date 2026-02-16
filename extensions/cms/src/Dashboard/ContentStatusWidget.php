<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Dashboard;

use Pulsar\Api\Internal;

use function array_sum;

/**
 * Dashboard widget showing content counts by publishing status with sparkline trend data.
 */
#[Internal(reason: 'CMS dashboard widget; implementation detail')]
final readonly class ContentStatusWidget implements DashboardWidgetInterface
{
    public function __construct(
        private ContentStatusQueryInterface $statusQuery,
        private ?string $tenantId = null,
    ) {}

    public function getName(): string
    {
        return 'content_status';
    }

    public function getData(): array
    {
        $counts = $this->statusQuery->countByStatus($this->tenantId);
        $trend = $this->statusQuery->getDailyCreationTrend(tenantId: $this->tenantId);

        return [
            'counts' => $counts,
            'sparkline' => $trend,
            'total' => array_sum($counts),
        ];
    }

    public function getTemplate(): string
    {
        return 'dashboard/widgets/content-status';
    }
}
