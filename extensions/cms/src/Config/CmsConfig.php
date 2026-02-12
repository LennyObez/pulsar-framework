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
     * @param string|null $homepageContentId Content ID to serve at GET /. When null, falls back to path='' lookup
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
     * @param FormsConfig $forms Form submission pipeline configuration
     */
    public function __construct(
        public string $defaultLocale = 'en',
        public array $supportedLocales = ['en'],
        public bool $defaultLocaleInUrl = false,
        public bool $editorialWorkflow = false,
        public bool $eventSourcing = false,
        public bool $atomicSnapshots = false,
        public int $maxHierarchyDepth = 10,
        public ?string $homepageContentId = null,
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
        public FormsConfig $forms = new FormsConfig(),
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $cacheData */
        $cacheData = (array) ($data['cache'] ?? []);
        /** @var array<string, mixed> $mediaData */
        $mediaData = (array) ($data['media'] ?? []);
        /** @var array<string, mixed> $commentsData */
        $commentsData = (array) ($data['comments'] ?? []);
        /** @var array<string, mixed> $seoData */
        $seoData = (array) ($data['seo'] ?? []);
        /** @var array<string, mixed> $themesData */
        $themesData = (array) ($data['themes'] ?? []);
        /** @var array<string, mixed> $securityData */
        $securityData = (array) ($data['security'] ?? []);
        /** @var array<string, mixed> $commerceData */
        $commerceData = (array) ($data['commerce'] ?? []);
        /** @var array<string, mixed> $liveCssData */
        $liveCssData = (array) ($data['live_css'] ?? []);
        /** @var array<string, mixed> $importData */
        $importData = (array) ($data['import'] ?? []);
        /** @var array<string, mixed> $notificationsData */
        $notificationsData = (array) ($data['notifications'] ?? []);
        /** @var array<string, mixed> $aiData */
        $aiData = (array) ($data['ai'] ?? []);
        /** @var array<string, mixed> $publishingData */
        $publishingData = (array) ($data['publishing'] ?? []);
        /** @var array<string, mixed> $formsData */
        $formsData = (array) ($data['forms'] ?? []);

        return new self(
            defaultLocale: (string) ($data['default_locale'] ?? 'en'),
            supportedLocales: (array) ($data['supported_locales'] ?? ['en']),
            defaultLocaleInUrl: (bool) ($data['default_locale_in_url'] ?? false),
            editorialWorkflow: (bool) ($data['editorial_workflow'] ?? false),
            eventSourcing: (bool) ($data['event_sourcing'] ?? false),
            atomicSnapshots: (bool) ($data['atomic_snapshots'] ?? false),
            maxHierarchyDepth: (int) ($data['max_hierarchy_depth'] ?? 10),
            homepageContentId: isset($data['homepage_content_id']) && is_string($data['homepage_content_id']) ? $data['homepage_content_id'] : null,
            cache: CmsCacheConfig::fromArray($cacheData),
            media: MediaConfig::fromArray($mediaData),
            comments: CommentsConfig::fromArray($commentsData),
            seo: SeoConfig::fromArray($seoData),
            themes: ThemesConfig::fromArray($themesData),
            security: CmsSecurityConfig::fromArray($securityData),
            commerce: isset($data['commerce']) ? CommerceConfig::fromArray($commerceData) : null,
            liveCss: LiveCssConfig::fromArray($liveCssData),
            import: ImportConfig::fromArray($importData),
            httpCacheTtlSeconds: (int) ($data['http_cache_ttl_seconds'] ?? 300),
            publicRateLimitContent: (int) ($data['public_rate_limit_content'] ?? 120),
            publicRateLimitCheckout: (int) ($data['public_rate_limit_checkout'] ?? 30),
            apiKeyRequired: (bool) ($data['api_key_required'] ?? false),
            notifications: NotificationConfig::fromArray($notificationsData),
            ai: AiConfig::fromArray($aiData),
            publishing: PublishingConfig::fromArray($publishingData),
            forms: FormsConfig::fromArray($formsData),
        );
    }
}
