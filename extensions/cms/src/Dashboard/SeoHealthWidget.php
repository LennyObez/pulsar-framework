<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Dashboard;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;

use function count;

/**
 * Dashboard widget showing SEO health: broken link count, sitemap status,
 * and last sitemap generation timestamp.
 */
#[Internal(reason: 'CMS dashboard widget — implementation detail')]
final readonly class SeoHealthWidget implements DashboardWidgetInterface
{
    public function __construct(
        private LinkHealthServiceInterface $linkHealthService,
        private ?DateTimeImmutable $lastSitemapGeneration = null,
        private bool $sitemapEnabled = true,
        private ?string $tenantId = null,
    ) {}

    public function getName(): string
    {
        return 'seo_health';
    }

    public function getData(): array
    {
        $brokenLinks = $this->linkHealthService->getBrokenLinks(
            tenantId: $this->tenantId,
            perPage: 100,
        );

        return [
            'broken_link_count' => count($brokenLinks),
            'sitemap_enabled' => $this->sitemapEnabled,
            'last_sitemap_generation' => $this->lastSitemapGeneration?->format('c'),
            'health_status' => $this->computeHealthStatus(count($brokenLinks)),
        ];
    }

    public function getTemplate(): string
    {
        return 'dashboard/widgets/seo-health';
    }

    private function computeHealthStatus(int $brokenLinkCount): string
    {
        if ($brokenLinkCount === 0 && $this->sitemapEnabled) {
            return 'healthy';
        }

        if ($brokenLinkCount > 10 || !$this->sitemapEnabled) {
            return 'unhealthy';
        }

        return 'degraded';
    }
}
