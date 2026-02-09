<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\LiveCss\LiveCssConfig;
use Pulsar\Extension\Cms\Tools\ImportConfig;

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
     * @param CommerceConfig|null $commerce Commerce subsystem configuration (null = disabled)
     * @param LiveCssConfig $liveCss Live CSS editor configuration
     * @param ImportConfig $import Import/export configuration
     * @param int $httpCacheTtlSeconds HTTP Cache-Control max-age/s-maxage for public content responses
     * @param int $publicRateLimitContent Max requests per minute for public content endpoints
     * @param int $publicRateLimitCheckout Max requests per minute for checkout endpoints
     * @param bool $apiKeyRequired When true, all content API requests must include a valid API key
     * @param NotificationConfig $notifications Workflow notification configuration
     * @param AiConfig $ai AI content assistant configuration
     * @param PublishingConfig $publishing Multi-channel publishing configuration
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
        public ?CommerceConfig $commerce = null,
        public LiveCssConfig $liveCss = new LiveCssConfig(),
        public ImportConfig $import = new ImportConfig(),
        public int $httpCacheTtlSeconds = 300,
        public int $publicRateLimitContent = 120,
        public int $publicRateLimitCheckout = 30,
        public bool $apiKeyRequired = false,
        public NotificationConfig $notifications = new NotificationConfig(),
        public AiConfig $ai = new AiConfig(),
        public PublishingConfig $publishing = new PublishingConfig(),
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
            commerce: isset($data['commerce']) ? CommerceConfig::fromArray((array) $data['commerce']) : null,
            liveCss: LiveCssConfig::fromArray((array) ($data['live_css'] ?? [])),
            import: ImportConfig::fromArray((array) ($data['import'] ?? [])),
            httpCacheTtlSeconds: (int) ($data['http_cache_ttl_seconds'] ?? 300),
            publicRateLimitContent: (int) ($data['public_rate_limit_content'] ?? 120),
            publicRateLimitCheckout: (int) ($data['public_rate_limit_checkout'] ?? 30),
            apiKeyRequired: (bool) ($data['api_key_required'] ?? false),
            notifications: NotificationConfig::fromArray((array) ($data['notifications'] ?? [])),
            ai: AiConfig::fromArray((array) ($data['ai'] ?? [])),
            publishing: PublishingConfig::fromArray((array) ($data['publishing'] ?? [])),
        );
    }
}
