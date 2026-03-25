<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\LiveCss\LiveCssConfig;
use Pulsar\Extension\Cms\Tools\ImportConfig;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Top-level CMS configuration DTO.
 *
 * @psalm-api Public top-level configuration loaded from config/cms.php during
 *            preBoot; consumed throughout the CMS by services and controllers.
 *
 * Loaded from config/cms.php during the preBoot phase. All values have
 * sensible defaults for non-regulated environments; regulated deployments
 * should enable editorialWorkflow, eventSourcing, and atomicSnapshots.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CmsConfig
{
    /**
     * @param string $siteName Human-readable site name used in Open Graph and feed titles
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
        public string $siteName = 'Pulsar CMS',
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
        $cacheData = is_array($data['cache'] ?? null) ? $data['cache'] : [];
        /** @var array<string, mixed> $mediaData */
        $mediaData = is_array($data['media'] ?? null) ? $data['media'] : [];
        /** @var array<string, mixed> $commentsData */
        $commentsData = is_array($data['comments'] ?? null) ? $data['comments'] : [];
        /** @var array<string, mixed> $seoData */
        $seoData = is_array($data['seo'] ?? null) ? $data['seo'] : [];
        /** @var array<string, mixed> $themesData */
        $themesData = is_array($data['themes'] ?? null) ? $data['themes'] : [];
        /** @var array<string, mixed> $securityData */
        $securityData = is_array($data['security'] ?? null) ? $data['security'] : [];
        /** @var array<string, mixed> $commerceData */
        $commerceData = is_array($data['commerce'] ?? null) ? $data['commerce'] : [];
        /** @var array<string, mixed> $liveCssData */
        $liveCssData = is_array($data['live_css'] ?? null) ? $data['live_css'] : [];
        /** @var array<string, mixed> $importData */
        $importData = is_array($data['import'] ?? null) ? $data['import'] : [];
        /** @var array<string, mixed> $notificationsData */
        $notificationsData = is_array($data['notifications'] ?? null) ? $data['notifications'] : [];
        /** @var array<string, mixed> $aiData */
        $aiData = is_array($data['ai'] ?? null) ? $data['ai'] : [];
        /** @var array<string, mixed> $publishingData */
        $publishingData = is_array($data['publishing'] ?? null) ? $data['publishing'] : [];
        /** @var array<string, mixed> $formsData */
        $formsData = is_array($data['forms'] ?? null) ? $data['forms'] : [];

        $rawSiteName = $data['site_name'] ?? null;
        $rawDefaultLocale = $data['default_locale'] ?? null;
        $rawSupportedLocales = $data['supported_locales'] ?? null;
        $rawDefaultLocaleInUrl = $data['default_locale_in_url'] ?? null;
        $rawEditorialWorkflow = $data['editorial_workflow'] ?? null;
        $rawEventSourcing = $data['event_sourcing'] ?? null;
        $rawAtomicSnapshots = $data['atomic_snapshots'] ?? null;
        $rawMaxHierarchyDepth = $data['max_hierarchy_depth'] ?? null;
        $rawHomepageContentId = $data['homepage_content_id'] ?? null;
        $rawHttpCacheTtl = $data['http_cache_ttl_seconds'] ?? null;
        $rawPublicRateLimitContent = $data['public_rate_limit_content'] ?? null;
        $rawPublicRateLimitCheckout = $data['public_rate_limit_checkout'] ?? null;
        $rawApiKeyRequired = $data['api_key_required'] ?? null;

        return new self(
            siteName: is_string($rawSiteName) ? $rawSiteName : 'Pulsar CMS',
            defaultLocale: is_string($rawDefaultLocale) ? $rawDefaultLocale : 'en',
            supportedLocales: is_array($rawSupportedLocales) ? array_values(array_filter($rawSupportedLocales, 'is_string')) : ['en'],
            defaultLocaleInUrl: is_bool($rawDefaultLocaleInUrl) ? $rawDefaultLocaleInUrl : false,
            editorialWorkflow: is_bool($rawEditorialWorkflow) ? $rawEditorialWorkflow : false,
            eventSourcing: is_bool($rawEventSourcing) ? $rawEventSourcing : false,
            atomicSnapshots: is_bool($rawAtomicSnapshots) ? $rawAtomicSnapshots : false,
            maxHierarchyDepth: is_int($rawMaxHierarchyDepth) ? $rawMaxHierarchyDepth : 10,
            homepageContentId: is_string($rawHomepageContentId) ? $rawHomepageContentId : null,
            cache: CmsCacheConfig::fromArray($cacheData),
            media: MediaConfig::fromArray($mediaData),
            comments: CommentsConfig::fromArray($commentsData),
            seo: SeoConfig::fromArray($seoData),
            themes: ThemesConfig::fromArray($themesData),
            security: CmsSecurityConfig::fromArray($securityData),
            commerce: isset($data['commerce']) ? CommerceConfig::fromArray($commerceData) : null,
            liveCss: LiveCssConfig::fromArray($liveCssData),
            import: ImportConfig::fromArray($importData),
            httpCacheTtlSeconds: is_int($rawHttpCacheTtl) ? $rawHttpCacheTtl : 300,
            publicRateLimitContent: is_int($rawPublicRateLimitContent) ? $rawPublicRateLimitContent : 120,
            publicRateLimitCheckout: is_int($rawPublicRateLimitCheckout) ? $rawPublicRateLimitCheckout : 30,
            apiKeyRequired: is_bool($rawApiKeyRequired) ? $rawApiKeyRequired : false,
            notifications: NotificationConfig::fromArray($notificationsData),
            ai: AiConfig::fromArray($aiData),
            publishing: PublishingConfig::fromArray($publishingData),
            forms: FormsConfig::fromArray($formsData),
        );
    }
}
