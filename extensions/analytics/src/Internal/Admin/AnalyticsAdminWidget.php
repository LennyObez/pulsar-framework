<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Admin;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Contracts\StatsServiceInterface;
use Pulsar\Extension\Analytics\Domain\BreakdownDimension;

use function count;

/**
 * Optional widget for the pulsar/admin dashboard.
 *
 * Shows summary analytics: visitors today, top page, active sites count.
 */
#[Internal(reason: 'Admin widget — optional integration')]
final readonly class AnalyticsAdminWidget
{
    public function __construct(
        private StatsServiceInterface $statsService,
        private SiteRepositoryInterface $siteRepository,
    ) {}

    /**
     * @return array{visitors_today: int, sites_count: int, top_page: string}
     */
    public function getSummary(): array
    {
        $sites = $this->siteRepository->findAll();
        $today = new DateTimeImmutable('today');
        $now = new DateTimeImmutable();

        $totalVisitors = 0;
        $topPage = '';
        $topPageVisitors = 0;

        foreach ($sites as $site) {
            $aggregate = $this->statsService->getAggregate($site->id, $today, $now);
            $totalVisitors += $aggregate['visitors'];

            $breakdown = $this->statsService->getBreakdown(
                $site->id,
                $today,
                $now,
                BreakdownDimension::Page,
                1,
            );

            if ($breakdown !== [] && $breakdown[0]['visitors'] > $topPageVisitors) {
                $topPage = $breakdown[0]['name'];
                $topPageVisitors = $breakdown[0]['visitors'];
            }
        }

        return [
            'visitors_today' => $totalVisitors,
            'sites_count' => count($sites),
            'top_page' => $topPage,
        ];
    }
}
