<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\LiveCss\LiveCssConfig;
use Pulsar\Extension\Cms\Tools\ImportConfig;
use Pulsar\Support\Coerce;

use function is_array;

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
     * @param array{
     *     site_name?: string,
     *     default_locale?: string,
     *     supported_locales?: list<string>,
     *     default_locale_in_url?: bool,
     *     editorial_workflow?: bool,
     *     event_sourcing?: bool,
     *     atomic_snapshots?: bool,
     *     max_hierarchy_depth?: int,
     *     homepage_content_id?: string|null,
     *     cache?: array<string, mixed>,
     *     media?: array<string, mixed>,
     *     comments?: array<string, mixed>,
     *     seo?: array<string, mixed>,
     *     themes?: array<string, mixed>,
     *     security?: array<string, mixed>,
     *     commerce?: array<string, mixed>|null,
     *     live_css?: array<string, mixed>,
     *     import?: array<string, mixed>,
     *     http_cache_ttl_seconds?: int,
     *     public_rate_limit_content?: int,
     *     public_rate_limit_checkout?: int,
     *     api_key_required?: bool,
     *     notifications?: array<string, mixed>,
     *     ai?: array<string, mixed>,
     *     publishing?: array<string, mixed>,
     *     forms?: array<string, mixed>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $sub = static function (string $k) use ($data): array {
            $value = $data[$k] ?? null;

            /** @var array<string, mixed> */
            return is_array($value) ? $value : [];
        };
        $commerceData = $data['commerce'] ?? null;

        return new self(
            siteName: Coerce::string($data['site_name'] ?? null, 'Pulsar CMS'),
            defaultLocale: Coerce::string($data['default_locale'] ?? null, 'en'),
            supportedLocales: Coerce::listOfString($data['supported_locales'] ?? null, ['en']),
            defaultLocaleInUrl: Coerce::strictBool($data['default_locale_in_url'] ?? null),
            editorialWorkflow: Coerce::strictBool($data['editorial_workflow'] ?? null),
            eventSourcing: Coerce::strictBool($data['event_sourcing'] ?? null),
            atomicSnapshots: Coerce::strictBool($data['atomic_snapshots'] ?? null),
            maxHierarchyDepth: Coerce::int($data['max_hierarchy_depth'] ?? null, 10),
            homepageContentId: Coerce::nullableString($data['homepage_content_id'] ?? null),
            cache: CmsCacheConfig::fromArray($sub('cache')),
            media: MediaConfig::fromArray($sub('media')),
            comments: CommentsConfig::fromArray($sub('comments')),
            seo: SeoConfig::fromArray($sub('seo')),
            themes: ThemesConfig::fromArray($sub('themes')),
            security: CmsSecurityConfig::fromArray($sub('security')),
            commerce: is_array($commerceData) ? CommerceConfig::fromArray($commerceData) : null,
            liveCss: LiveCssConfig::fromArray($sub('live_css')),
            import: ImportConfig::fromArray($sub('import')),
            httpCacheTtlSeconds: Coerce::int($data['http_cache_ttl_seconds'] ?? null, 300),
            publicRateLimitContent: Coerce::int($data['public_rate_limit_content'] ?? null, 120),
            publicRateLimitCheckout: Coerce::int($data['public_rate_limit_checkout'] ?? null, 30),
            apiKeyRequired: Coerce::strictBool($data['api_key_required'] ?? null),
            notifications: NotificationConfig::fromArray($sub('notifications')),
            ai: AiConfig::fromArray($sub('ai')),
            publishing: PublishingConfig::fromArray($sub('publishing')),
            forms: FormsConfig::fromArray($sub('forms')),
        );
    }
}
