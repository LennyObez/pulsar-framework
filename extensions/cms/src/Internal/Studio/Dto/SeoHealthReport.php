<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Studio\Dto;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Content\Content;
use Pulsar\Extension\Cms\Seo\LinkHealthCheck;

/**
 * Aggregated SEO health report for the Studio panel.
 */
#[Internal]
final readonly class SeoHealthReport
{
    /**
     * @param list<LinkHealthCheck> $brokenLinks
     * @param list<Content> $orphanContent
     * @param list<string> $sitemapErrors
     */
    public function __construct(
        public int $brokenLinkCount,
        public array $brokenLinks,
        public int $orphanContentCount,
        public array $orphanContent,
        public ?DateTimeImmutable $sitemapLastGenerated,
        public int $sitemapEntryCount,
        public array $sitemapErrors,
    ) {}
}
