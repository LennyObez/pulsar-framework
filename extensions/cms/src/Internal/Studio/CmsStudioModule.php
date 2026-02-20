<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Studio;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Contracts\StudioModuleInterface;
use Pulsar\Extension\Studio\Contracts\StudioNavEntry;
use Pulsar\Routing\RouterInterface;

/**
 * Studio module integration for the CMS extension.
 *
 * Registers four panels in the Studio sidebar: Audit Trail,
 * Content Cache Inspector, Media Processing Queue, and SEO Health Report.
 */
#[Internal]
final readonly class CmsStudioModule implements StudioModuleInterface
{
    public function __construct(
        private CmsAuditPanel $auditPanel,
        private ContentCacheInspectorPanel $cachePanel,
        private MediaProcessingQueuePanel $mediaPanel,
        private SeoHealthReportPanel $seoPanel,
    ) {}

    #[Override]
    public function moduleId(): string
    {
        return 'cms';
    }

    #[Override]
    public function label(): string
    {
        return 'CMS';
    }

    #[Override]
    public function icon(): string
    {
        return 'file-text';
    }

    #[Override]
    public function navEntries(): array
    {
        return [
            new StudioNavEntry(
                label: 'Audit trail',
                href: '/studio/cms/audit',
                icon: 'shield',
                order: 0,
            ),
            new StudioNavEntry(
                label: 'Content cache',
                href: '/studio/cms/cache',
                icon: 'database',
                order: 1,
            ),
            new StudioNavEntry(
                label: 'Media queue',
                href: '/studio/cms/media-queue',
                icon: 'image',
                order: 2,
            ),
            new StudioNavEntry(
                label: 'SEO health',
                href: '/studio/cms/seo',
                icon: 'search',
                order: 3,
            ),
        ];
    }

    #[Override]
    public function registerRoutes(RouterInterface $router): void
    {
        // Routes are registered by the CmsExtension directly;
        // Studio module provides navigation entries only.
    }

    #[Override]
    public function routePrefix(): string
    {
        return '/studio/cms';
    }

    #[Override]
    public function navOrder(): int
    {
        return 60;
    }

    /**
     * Get the audit panel data provider.
     */
    public function auditPanel(): CmsAuditPanel
    {
        return $this->auditPanel;
    }

    /**
     * Get the content cache inspector panel data provider.
     */
    public function cachePanel(): ContentCacheInspectorPanel
    {
        return $this->cachePanel;
    }

    /**
     * Get the media processing queue panel data provider.
     */
    public function mediaPanel(): MediaProcessingQueuePanel
    {
        return $this->mediaPanel;
    }

    /**
     * Get the SEO health report panel data provider.
     */
    public function seoPanel(): SeoHealthReportPanel
    {
        return $this->seoPanel;
    }
}
