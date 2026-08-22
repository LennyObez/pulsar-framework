<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Studio;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Internal\Studio\Dto\SeoHealthReport;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;

use function count;

/**
 * Studio panel data provider for the SEO health report.
 *
 * Displays broken links (count + list), orphan content (published content
 * with no taxonomy terms and no menu links), and sitemap status (last
 * generation time, entry count, any errors).
 *
 * @psalm-api Resolved from the DI container by CmsStudioModule; not
 *            instantiated by name.
 */
#[Internal]
final readonly class SeoHealthReportPanel
{
    public function __construct(
        private LinkHealthServiceInterface $linkHealthService,
    ) {}

    /**
     * Generate a comprehensive SEO health report.
     *
     * @param string|null $tenantId Scope to a specific tenant
     * @param DateTimeImmutable|null $sitemapLastGenerated Last sitemap generation timestamp
     * @param int $sitemapEntryCount Number of entries in the sitemap
     * @param list<string> $sitemapErrors Errors from last sitemap generation
     */
    public function generateReport(
        ?string $tenantId = null,
        ?DateTimeImmutable $sitemapLastGenerated = null,
        int $sitemapEntryCount = 0,
        array $sitemapErrors = [],
    ): SeoHealthReport {
        $brokenLinks = $this->linkHealthService->getBrokenLinks($tenantId, 1, 100);
        $orphanContent = $this->linkHealthService->getOrphanContent($tenantId, 1, 100);

        return new SeoHealthReport(
            brokenLinkCount: count($brokenLinks),
            brokenLinks: $brokenLinks,
            orphanContentCount: count($orphanContent),
            orphanContent: $orphanContent,
            sitemapLastGenerated: $sitemapLastGenerated,
            sitemapEntryCount: $sitemapEntryCount,
            sitemapErrors: $sitemapErrors,
        );
    }

    /**
     * Get the count of broken links without fetching all details.
     */
    public function brokenLinkCount(?string $tenantId = null): int
    {
        return count($this->linkHealthService->getBrokenLinks($tenantId, 1, 1000));
    }

    /**
     * Get the count of orphan content pages.
     */
    public function orphanContentCount(?string $tenantId = null): int
    {
        return count($this->linkHealthService->getOrphanContent($tenantId, 1, 1000));
    }
}
