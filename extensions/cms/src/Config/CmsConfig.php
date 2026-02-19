<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;

/**
 * Top-level CMS configuration DTO.
 *
 * Loaded from config/cms.php during the preBoot phase. All values have
 * sensible defaults for non-regulated environments; regulated deployments
 * should enable editorialWorkflow, eventSourcing, and atomicSnapshots.
 */
#[Api(since: '1.0.0')]
final readonly class CmsConfig
{
    /**
     * @param string $defaultLocale BCP 47 default locale code
     * @param list<string> $supportedLocales All locale codes the CMS serves
     * @param bool $defaultLocaleInUrl When false, default locale at /{path}; when true, /{locale}/{path}
     * @param bool $editorialWorkflow Enable Draft → InReview → Approved → Published pipeline
     * @param bool $eventSourcing Enable append-only event log for content mutations
     * @param bool $atomicSnapshots Enable all-locale atomic content snapshots on publish
     * @param int $maxHierarchyDepth Maximum page nesting depth (cycle detection)
     * @param CmsCacheConfig $cache Caching configuration
     * @param MediaConfig $media Media upload and processing configuration
     * @param CommentsConfig $comments Comments system configuration
     * @param SeoConfig $seo SEO and link health configuration
     * @param ThemesConfig $themes Theme system configuration
     * @param CmsSecurityConfig $security CMS security configuration
     */
    public function __construct(
        public string $defaultLocale = 'en',
        public array $supportedLocales = ['en'],
        public bool $defaultLocaleInUrl = false,
        public bool $editorialWorkflow = false,
        public bool $eventSourcing = false,
        public bool $atomicSnapshots = false,
        public int $maxHierarchyDepth = 10,
        public CmsCacheConfig $cache = new CmsCacheConfig(),
        public MediaConfig $media = new MediaConfig(),
        public CommentsConfig $comments = new CommentsConfig(),
        public SeoConfig $seo = new SeoConfig(),
        public ThemesConfig $themes = new ThemesConfig(),
        public CmsSecurityConfig $security = new CmsSecurityConfig(),
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            defaultLocale: (string) ($data['default_locale'] ?? 'en'),
            supportedLocales: (array) ($data['supported_locales'] ?? ['en']),
            defaultLocaleInUrl: (bool) ($data['default_locale_in_url'] ?? false),
            editorialWorkflow: (bool) ($data['editorial_workflow'] ?? false),
            eventSourcing: (bool) ($data['event_sourcing'] ?? false),
            atomicSnapshots: (bool) ($data['atomic_snapshots'] ?? false),
            maxHierarchyDepth: (int) ($data['max_hierarchy_depth'] ?? 10),
            cache: CmsCacheConfig::fromArray((array) ($data['cache'] ?? [])),
            media: MediaConfig::fromArray((array) ($data['media'] ?? [])),
            comments: CommentsConfig::fromArray((array) ($data['comments'] ?? [])),
            seo: SeoConfig::fromArray((array) ($data['seo'] ?? [])),
            themes: ThemesConfig::fromArray((array) ($data['themes'] ?? [])),
            security: CmsSecurityConfig::fromArray((array) ($data['security'] ?? [])),
        );
    }
}
