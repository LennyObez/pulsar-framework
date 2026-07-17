<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\AI\AiClientInterface;
use Pulsar\AI\Provider\AnthropicProvider as CoreAnthropicProvider;
use Pulsar\AI\Provider\OpenAiProvider as CoreOpenAiProvider;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\NullAuditLogger;
use Pulsar\Cache\Application\CacheManagerInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Config\SessionConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\ABTest\ExperimentRepositoryInterface;
use Pulsar\Extension\Cms\AI\ContentAssistant;
use Pulsar\Extension\Cms\BlockEditor\BlockRenderer;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeRegistry;
use Pulsar\Extension\Cms\Collaboration\CollaborationRepositoryInterface;
use Pulsar\Extension\Cms\Comments\CommentBodyPolicy;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Comments\CommentService;
use Pulsar\Extension\Cms\Comments\CommentServiceInterface;
use Pulsar\Extension\Cms\Commerce\ApiKeyRepositoryInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Content\RevisionService;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\Docs\DocVersionServiceInterface;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Forms\FormSubmissionRepositoryInterface;
use Pulsar\Extension\Cms\Forms\FormSubmissionServiceInterface;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamScorer;
use Pulsar\Extension\Cms\Http\Controller\Api\CollaborationApiController;
use Pulsar\Extension\Cms\Http\Controller\FormSubmissionController as PublicFormSubmissionController;
use Pulsar\Extension\Cms\Http\Middleware\CmsApiKeyMiddleware;
use Pulsar\Extension\Cms\Http\Middleware\CmsLocaleMiddleware;
use Pulsar\Extension\Cms\I18n\HreflangGenerator;
use Pulsar\Extension\Cms\I18n\LocaleResolver;
use Pulsar\Extension\Cms\Internal\ABTest\ExperimentService;
use Pulsar\Extension\Cms\Internal\ABTest\TrafficSplitter;
use Pulsar\Extension\Cms\Internal\AI\CmsPromptTemplates;
use Pulsar\Extension\Cms\Internal\Cache\CachedContentRepository;
use Pulsar\Extension\Cms\Internal\Cache\CachedMenuRepository;
use Pulsar\Extension\Cms\Internal\Cache\CachedSettingsService;
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheInvalidator;
use Pulsar\Extension\Cms\Internal\Collaboration\CollaborationService as CollaborationServiceImpl;
use Pulsar\Extension\Cms\Internal\Docs\DocVersionService;
use Pulsar\Extension\Cms\Internal\Forms\ContentHeuristicScorer;
use Pulsar\Extension\Cms\Internal\Forms\FormSubmissionService;
use Pulsar\Extension\Cms\Internal\Forms\HoneypotDetector;
use Pulsar\Extension\Cms\Internal\Forms\ManagedChallengeDetector;
use Pulsar\Extension\Cms\Internal\Forms\RateLimitDetector;
use Pulsar\Extension\Cms\Internal\Forms\TimingDetector;
use Pulsar\Extension\Cms\Internal\Http\AiRequestParser;
use Pulsar\Extension\Cms\Internal\Newsletter\CampaignEditorService;
use Pulsar\Extension\Cms\Internal\Newsletter\NewsletterSubscriptionService;
use Pulsar\Extension\Cms\Internal\Notification\CmsNotificationDispatcher;
use Pulsar\Extension\Cms\Internal\Persistence\DbDocVersionRepository;
use Pulsar\Extension\Cms\Internal\Publishing\PublishingOrchestrator;
use Pulsar\Extension\Cms\Internal\Publishing\RssChannel;
use Pulsar\Extension\Cms\Internal\Publishing\StaticSiteChannel;
use Pulsar\Extension\Cms\Internal\Publishing\WebChannel;
use Pulsar\Extension\Cms\Internal\Search\SearchServiceFactory;
use Pulsar\Extension\Cms\Internal\Security\ClientFingerprintResolver;
use Pulsar\Extension\Cms\Internal\Security\CmsKeyManager;
use Pulsar\Extension\Cms\Internal\Security\SafeHttpClient;
use Pulsar\Extension\Cms\Internal\Seo\ArticleStructuredDataGenerator;
use Pulsar\Extension\Cms\Internal\Seo\FeedGenerator;
use Pulsar\Extension\Cms\Internal\Seo\LinkHealthChecker;
use Pulsar\Extension\Cms\Internal\Seo\OrganizationStructuredDataGenerator;
use Pulsar\Extension\Cms\Internal\Seo\ProductStructuredDataGenerator;
use Pulsar\Extension\Cms\Internal\Seo\RedirectManager;
use Pulsar\Extension\Cms\Internal\Seo\RobotsTxtGenerator;
use Pulsar\Extension\Cms\Internal\Seo\SeoService;
use Pulsar\Extension\Cms\Internal\Seo\SitemapGenerator;
use Pulsar\Extension\Cms\Internal\Seo\WebPageStructuredDataGenerator;
use Pulsar\Extension\Cms\Internal\Tools\BackupService;
use Pulsar\Extension\Cms\Internal\Tools\ExportBundleGenerator;
use Pulsar\Extension\Cms\Internal\Tools\ImportExportService;
use Pulsar\Extension\Cms\Internal\Tools\ImportParser;
use Pulsar\Extension\Cms\Internal\Tools\MediaBundleExporter;
use Pulsar\Extension\Cms\Internal\Tools\MediaBundleImporter;
use Pulsar\Extension\Cms\Internal\Tools\SiteDefinitionParser;
use Pulsar\Extension\Cms\Internal\Tools\ToolsService;
use Pulsar\Extension\Cms\Media\ImageProcessor;
use Pulsar\Extension\Cms\Media\ImageProcessorInterface;
use Pulsar\Extension\Cms\Media\ImageVariantGenerator;
use Pulsar\Extension\Cms\Media\License\LicenseBadgeRenderer;
use Pulsar\Extension\Cms\Media\LocalDisk;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaService;
use Pulsar\Extension\Cms\Media\MediaServiceInterface;
use Pulsar\Extension\Cms\Media\Metadata\ExifExtractor;
use Pulsar\Extension\Cms\Media\Processing\ImagickImageProcessor;
use Pulsar\Extension\Cms\Media\ResponsiveImageRenderer;
use Pulsar\Extension\Cms\Media\Security\FilenameSanitizer;
use Pulsar\Extension\Cms\Media\Security\FileValidator;
use Pulsar\Extension\Cms\Media\Security\HotlinkProtectionMiddleware;
use Pulsar\Extension\Cms\Media\Security\PdfValidator;
use Pulsar\Extension\Cms\Media\Security\SvgSanitizer;
use Pulsar\Extension\Cms\Media\Watermark\WatermarkService;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGenerator;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGeneratorInterface;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\CampaignEditorServiceInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterCampaignRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriberRepositoryInterface;
use Pulsar\Extension\Cms\Newsletter\NewsletterSubscriptionServiceInterface;
use Pulsar\Extension\Cms\Publishing\ChannelRegistry;
use Pulsar\Extension\Cms\Search\SearchAnalyticsRepositoryInterface;
use Pulsar\Extension\Cms\Search\SearchServiceInterface;
use Pulsar\Extension\Cms\Security\QrCodeEncoder;
use Pulsar\Extension\Cms\Seo\FeedGeneratorInterface;
use Pulsar\Extension\Cms\Seo\LinkHealthRepositoryInterface;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;
use Pulsar\Extension\Cms\Seo\RedirectManagerInterface;
use Pulsar\Extension\Cms\Seo\RobotsTxtGeneratorInterface;
use Pulsar\Extension\Cms\Seo\SeoServiceInterface;
use Pulsar\Extension\Cms\Seo\SitemapGeneratorInterface;
use Pulsar\Extension\Cms\Settings\SettingsService;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyService;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyServiceInterface;
use Pulsar\Extension\Cms\Tools\BackupServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportAnalyzer;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\MediaBundleExporterInterface;
use Pulsar\Extension\Cms\Tools\ToolsServiceInterface;
use Pulsar\Extension\Cms\Users\CmsUserRepositoryInterface;
use Pulsar\Extension\Cms\Workflow\ContentLockService;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowService;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\I18n\Locale\UrlPrefixExtractor;
use Pulsar\Mail\MailManager;
use Pulsar\Mail\MailManagerInterface;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerConfig;
use Pulsar\Security\AntiSpam\AiCrawler\AiCrawlerRobotsPolicy;
use Pulsar\Security\AntiSpam\CaptchaVerifierInterface;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;
use RuntimeException;

use function extension_loaded;

/**
 * Binds CMS core services: settings, taxonomy, media, comments, search, SEO,
 * navigation, i18n, editorial workflow, tools/backup, security, block editor,
 * and content controller.
 *
 * @psalm-api Instantiated by name from CmsServiceProvider::register() to wire
 *            the core service bindings into the DI container.
 */
#[Internal(reason: 'CMS service wiring; use interfaces for public API')]
final readonly class CmsCoreServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        if (!$container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var ConnectionInterface $connection */
        $connection = $container->get(ConnectionInterface::class);

        /** @var CmsConfig $config */
        $config = $container->has(CmsConfig::class)
            ? $container->get(CmsConfig::class)
            : new CmsConfig();

        /** @var AuditLoggerInterface|null $auditLogger */
        $auditLogger = $container->has(AuditLoggerInterface::class)
            ? $container->get(AuditLoggerInterface::class)
            : null;

        /** @var LoggerInterface $logger */
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : new NullLogger();

        // Settings service
        if ($auditLogger !== null) {
            $container->instance(
                SettingsServiceInterface::class,
                new SettingsService($connection, $auditLogger),
            );
        }

        // Taxonomy service
        $container->instance(
            TaxonomyServiceInterface::class,
            new TaxonomyService($connection),
        );

        // Block editor registry and renderer (mutable singleton; blocks registered during boot)
        $blockTypeRegistry = new BlockTypeRegistry();
        $container->instance(BlockTypeRegistry::class, $blockTypeRegistry);
        $container->instance(BlockRenderer::class, new BlockRenderer($blockTypeRegistry));

        // Media stack
        $disk = new LocalDisk($config->media->storagePath);
        $container->instance(MediaDiskInterface::class, $disk);

        // Image processor: prefer Imagick when available for higher quality (Lanczos, ICC profiles)
        $imageProcessor = extension_loaded('imagick')
            ? new ImagickImageProcessor($config->media)
            : new ImageProcessor($config->media);
        $container->instance(ImageProcessorInterface::class, $imageProcessor);

        // EXIF metadata extraction
        $exifExtractor = new ExifExtractor($logger);
        $container->instance(ExifExtractor::class, $exifExtractor);

        // Watermark service (config from WatermarkConfig; always registered, no-ops when disabled)
        $watermarkConfig = new Config\WatermarkConfig();
        $watermarkService = new WatermarkService($watermarkConfig, $logger);
        $container->instance(WatermarkService::class, $watermarkService);

        // License badge renderer
        $container->instance(LicenseBadgeRenderer::class, new LicenseBadgeRenderer());

        // Hotlink protection middleware (reads config from CmsSecurityConfig)
        $hotlinkDomains = $config->security->hotlinkAllowedDomains;
        $hotlinkEnabled = $config->security->hotlinkProtection;
        $container->instance(
            HotlinkProtectionMiddleware::class,
            new HotlinkProtectionMiddleware(
                allowedDomains: $hotlinkDomains,
                enabled: $hotlinkEnabled,
            ),
        );

        $fileValidator = new FileValidator($config->media);
        $filenameSanitizer = new FilenameSanitizer();
        $svgSanitizer = new SvgSanitizer();
        $pdfValidator = new PdfValidator();

        /** @var MediaRepositoryInterface $mediaRepository */
        $mediaRepository = $container->get(MediaRepositoryInterface::class);

        $variantGenerator = new ImageVariantGenerator($imageProcessor, $disk);
        $container->instance(ImageVariantGenerator::class, $variantGenerator);

        $container->instance(
            MediaServiceInterface::class,
            new MediaService(
                $disk,
                $imageProcessor,
                $fileValidator,
                $svgSanitizer,
                $mediaRepository,
                $config->media,
                $auditLogger,
                $filenameSanitizer,
                $pdfValidator,
                $logger,
                $variantGenerator,
            ),
        );

        // Comment stack
        /** @var CommentRepositoryInterface $commentRepository */
        $commentRepository = $container->get(CommentRepositoryInterface::class);

        /** @var ContentRepositoryInterface $contentRepository */
        $contentRepository = $container->get(ContentRepositoryInterface::class);

        if (!$container->has(SafeHtmlPolicy::class)) {
            $container->instance(
                SafeHtmlPolicy::class,
                new SafeHtmlPolicy($auditLogger ?? new NullAuditLogger()),
            );
        }

        /** @var SafeHtmlPolicy $safeHtmlPolicy */
        $safeHtmlPolicy = $container->get(SafeHtmlPolicy::class);

        $commentBodyPolicy = new CommentBodyPolicy($safeHtmlPolicy);

        if ($auditLogger !== null) {
            $container->instance(
                CommentServiceInterface::class,
                new CommentService(
                    $commentRepository,
                    $contentRepository,
                    $commentBodyPolicy,
                    $auditLogger,
                ),
            );
        }

        // Search stack
        /** @var SearchAnalyticsRepositoryInterface $analyticsRepository */
        $analyticsRepository = $container->get(SearchAnalyticsRepositoryInterface::class);

        /** @var string|null $tenantId */
        $tenantId = null;

        $container->instance(
            SearchServiceInterface::class,
            SearchServiceFactory::create($connection, $analyticsRepository, $tenantId),
        );

        /** @var ContentTranslationRepositoryInterface $translationRepository */
        $translationRepository = $container->get(ContentTranslationRepositoryInterface::class);

        // SEO stack: structured data generators
        $structuredDataGenerators = [
            new ArticleStructuredDataGenerator(),
            new WebPageStructuredDataGenerator(),
            new OrganizationStructuredDataGenerator($config),
        ];

        if ($container->has(Commerce\ProductRepositoryInterface::class)) {
            /** @var Commerce\ProductRepositoryInterface $productRepo */
            $productRepo = $container->get(Commerce\ProductRepositoryInterface::class);
            $structuredDataGenerators[] = new ProductStructuredDataGenerator($productRepo);
        }

        $container->instance(
            SeoServiceInterface::class,
            new SeoService(
                $config,
                $translationRepository,
                $structuredDataGenerators,
            ),
        );

        $container->instance(
            SitemapGeneratorInterface::class,
            new SitemapGenerator($contentRepository, $translationRepository, $config),
        );

        $aiCrawlerPolicy = null;
        if ($container->has(AiCrawlerConfig::class)) {
            /** @var AiCrawlerConfig $aiCrawlerConfig */
            $aiCrawlerConfig = $container->get(AiCrawlerConfig::class);
            $aiCrawlerPolicy = new AiCrawlerRobotsPolicy($aiCrawlerConfig);
        }

        $container->instance(
            RobotsTxtGeneratorInterface::class,
            new RobotsTxtGenerator($aiCrawlerPolicy),
        );

        $container->instance(
            FeedGeneratorInterface::class,
            new FeedGenerator($contentRepository, $translationRepository, $config),
        );

        /** @var RedirectRepositoryInterface $redirectRepository */
        $redirectRepository = $container->get(RedirectRepositoryInterface::class);

        $container->instance(
            RedirectManagerInterface::class,
            new RedirectManager($redirectRepository, $logger),
        );

        /** @var EventDispatcherInterface|null $eventDispatcher */
        $eventDispatcher = $container->has(EventDispatcherInterface::class)
            ? $container->get(EventDispatcherInterface::class)
            : null;

        if ($eventDispatcher !== null) {
            /** @var LinkHealthRepositoryInterface $linkHealthRepository */
            $linkHealthRepository = $container->get(LinkHealthRepositoryInterface::class);

            $container->instance(
                LinkHealthServiceInterface::class,
                new LinkHealthChecker(
                    $contentRepository,
                    $translationRepository,
                    $linkHealthRepository,
                    $eventDispatcher,
                    $logger,
                    $config,
                ),
            );
        }

        // Tools stack (GDPR)
        $container->instance(
            ToolsServiceInterface::class,
            new ToolsService($connection, $auditLogger),
        );

        // Security stack
        $securityConfig = $config->security;

        $container->instance(
            SafeHttpClient::class,
            new SafeHttpClient($securityConfig, $logger),
        );

        $container->instance(QrCodeEncoder::class, new QrCodeEncoder());

        // CmsKeyManager (requires MasterKey)
        if ($container->has(MasterKey::class)) {
            /** @var MasterKey $masterKey */
            $masterKey = $container->get(MasterKey::class);
            $cmsKeyManager = new CmsKeyManager($masterKey);
            $container->instance(CmsKeyManager::class, $cmsKeyManager);

            // ClientFingerprintResolver uses a derived HMAC key
            $hmacKey = $cmsKeyManager->previewKey();
            $container->instance(
                ClientFingerprintResolver::class,
                new ClientFingerprintResolver($securityConfig, $hmacKey),
            );

            // CmsApiKeyMiddleware: keyed BLAKE2b hash for API key storage
            // (MED-5). Pepper is derived from the master key via subkey 15
            // so the on-disk hash cannot be brute-forced offline.
            if (
                $container->has(HmacInterface::class)
                && $container->has(ApiKeyRepositoryInterface::class)
            ) {
                /** @var HmacInterface $hmac */
                $hmac = $container->get(HmacInterface::class);
                /** @var ApiKeyRepositoryInterface $apiKeyRepository */
                $apiKeyRepository = $container->get(ApiKeyRepositoryInterface::class);

                $container->instance(
                    CmsApiKeyMiddleware::class,
                    new CmsApiKeyMiddleware(
                        $apiKeyRepository,
                        $config,
                        $hmac,
                        $cmsKeyManager->apiKeyHashKey(),
                    ),
                );
            }
        }

        // Editorial workflow service
        if ($auditLogger !== null) {
            $workflowService = new EditorialWorkflowService(
                $connection,
                $contentRepository,
                $auditLogger,
            );
            $container->instance(EditorialWorkflowServiceInterface::class, $workflowService);
        }

        // Revision service
        /** @var ContentRevisionRepositoryInterface $revisionRepository */
        $revisionRepository = $container->get(ContentRevisionRepositoryInterface::class);

        $container->instance(
            RevisionService::class,
            new RevisionService($revisionRepository, $translationRepository),
        );

        // ContentLockService (needs DB + audit)
        if ($auditLogger !== null) {
            $container->instance(
                ContentLockServiceInterface::class,
                new ContentLockService($connection, $auditLogger),
            );
        }

        // Collaboration service
        if ($container->has(CollaborationRepositoryInterface::class)) {
            /** @var CollaborationRepositoryInterface $collaborationRepository */
            $collaborationRepository = $container->get(CollaborationRepositoryInterface::class);

            $collaborationService = new CollaborationServiceImpl($collaborationRepository, $auditLogger);
            $container->instance(CollaborationServiceImpl::class, $collaborationService);

            // API controller
            $container->instance(
                CollaborationApiController::class,
                new CollaborationApiController($collaborationService),
            );
        }

        // A/B Testing stack
        if ($container->has(ExperimentRepositoryInterface::class)) {
            /** @var ExperimentRepositoryInterface $experimentRepository */
            $experimentRepository = $container->get(ExperimentRepositoryInterface::class);

            $trafficSplitter = new TrafficSplitter();
            $container->instance(TrafficSplitter::class, $trafficSplitter);

            $experimentService = new ExperimentService($experimentRepository, $trafficSplitter);
            $container->instance(ExperimentService::class, $experimentService);
        }

        // Import/Export/Backup stack
        $this->bindImportExportServices(
            $container,
            $connection,
            $config,
            $contentRepository,
            $translationRepository,
            $redirectRepository,
            $mediaRepository,
            $disk,
            $auditLogger,
        );

        // Navigation stack
        $breadcrumbGenerator = new BreadcrumbGenerator(
            $contentRepository,
            $translationRepository,
            $config,
        );
        $container->instance(BreadcrumbGeneratorInterface::class, $breadcrumbGenerator);

        // I18n stack
        $urlPrefixExtractor = new UrlPrefixExtractor();
        $localeResolver = new LocaleResolver($urlPrefixExtractor);
        $container->instance(LocaleResolver::class, $localeResolver);

        // Register CmsLocaleMiddleware as a lazy factory. The middleware needs the final
        // CmsConfig which is only loaded during preBoot() (after register()). Binding eagerly
        // here would capture the default config with only 'en' as supported locale. The factory
        // resolves CmsConfig from the container at first access, getting the project's config.
        $container->bind(
            CmsLocaleMiddleware::class,
            static fn() => new CmsLocaleMiddleware(
                $localeResolver,
                $container->get(CmsConfig::class),
            ),
        );

        $hreflangGenerator = new HreflangGenerator(
            $translationRepository,
            $urlPrefixExtractor,
        );
        $container->instance(HreflangGenerator::class, $hreflangGenerator);

        // Content controller (front-office)
        /** @var ContentBlockRepositoryInterface $blockRepository */
        $blockRepository = $container->get(ContentBlockRepositoryInterface::class);

        /** @var FieldRegistryRepositoryInterface $fieldRepository */
        $fieldRepository = $container->get(FieldRegistryRepositoryInterface::class);

        if (!$container->has(CmsKeyManager::class)) {
            throw new RuntimeException(
                'CmsKeyManager is not registered. Ensure PULSAR_MASTER_KEY is set in your .env file. '
                . 'Run `pulsar key:generate` to create one.',
            );
        }

        /** @var CmsKeyManager $cmsKeyManager */
        $cmsKeyManager = $container->get(CmsKeyManager::class);

        /** @var SeoServiceInterface $seoService */
        $seoService = $container->get(SeoServiceInterface::class);

        // Defer template engine resolution: the Kernel rebuilds it after extensions boot
        // to include extension view paths. Resolve lazily via a closure that captures $container.
        // At this point the engine exists but has no extension paths yet; we pass null and
        // let ContentController fall back to Response::getTemplateEngine() which is always
        // updated by the Kernel's registerExtensionViewPaths().
        $templateEngine = null;

        $projectViewsPath = '';
        if ($container->has('app.base_path')) {
            /** @var string $appBasePath */
            $appBasePath = $container->get('app.base_path');
            $candidatePath = $appBasePath . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views';
            if (is_dir($candidatePath)) {
                $projectViewsPath = $candidatePath;
            }
        } elseif (is_dir(base_path('resources/views'))) {
            $projectViewsPath = base_path('resources/views');
        }

        // Resolve block renderer
        $blockRenderer = $container->has(BlockRenderer::class)
            ? $container->get(BlockRenderer::class)
            : null;

        // Resolve menu repository for navigation
        $menuRepository = $container->has(MenuRepositoryInterface::class)
            ? $container->get(MenuRepositoryInterface::class)
            : null;

        // Resolve theme repository for template resolution
        $themeRepository = $container->has(\Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface::class)
            ? $container->get(\Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface::class)
            : null;

        // Lazy binding: CmsConfig is loaded during preBoot() with the project's
        // config/cms.php. At register() time, only the default config (['en']) exists.
        // Lazy binding ensures the ContentController gets the final CmsConfig.
        $container->bind(
            Http\Controller\ContentController::class,
            static fn() => new Http\Controller\ContentController(
                $contentRepository,
                $translationRepository,
                $blockRepository,
                $redirectRepository,
                $fieldRepository,
                $breadcrumbGenerator,
                $hreflangGenerator,
                $cmsKeyManager,
                $safeHtmlPolicy,
                $container->get(CmsConfig::class),
                $seoService,
                $templateEngine,
                $blockRenderer,
                $menuRepository,
                $themeRepository,
                $projectViewsPath,
            ),
        );

        // Front-office account controller — lazy factory so register() does not
        // eagerly resolve the customer repository (which is wired separately and
        // may be absent in minimal/bootstrapping containers).
        $container->bind(
            Http\Controller\AccountController::class,
            static fn() => new Http\Controller\AccountController(
                $container->get(Commerce\CustomerRepositoryInterface::class),
                $container->get(Account\AccountSectionRegistry::class),
                $container->has(\Pulsar\View\Engine\TemplateEngineInterface::class)
                    ? $container->get(\Pulsar\View\Engine\TemplateEngineInterface::class)
                    : null,
            ),
        );

        // REST API controllers
        $container->instance(
            Http\Controller\Api\ContentApiController::class,
            new Http\Controller\Api\ContentApiController(
                $contentRepository,
                $translationRepository,
                $blockRepository,
                $fieldRepository,
                $config,
            ),
        );

        /** @var TaxonomyRepositoryInterface $taxonomyRepository */
        $taxonomyRepository = $container->get(TaxonomyRepositoryInterface::class);

        $container->instance(
            Http\Controller\Api\TaxonomyApiController::class,
            new Http\Controller\Api\TaxonomyApiController($taxonomyRepository, $config),
        );

        $container->instance(
            Http\Controller\Api\MediaApiController::class,
            new Http\Controller\Api\MediaApiController(
                $container->get(MediaServiceInterface::class),
                $mediaRepository,
            ),
        );

        if ($config->commerce !== null
            && $container->has(Commerce\ProductRepositoryInterface::class)
            && $container->has(Commerce\OrderRepositoryInterface::class)
        ) {
            $container->instance(
                Http\Controller\Api\CommerceApiController::class,
                new Http\Controller\Api\CommerceApiController(
                    $container->get(Commerce\ProductRepositoryInterface::class),
                    $container->get(Commerce\OrderRepositoryInterface::class),
                    $config,
                ),
            );
        }

        // Content negotiation middleware for REST API
        $container->instance(
            Http\Middleware\CmsApiContentNegotiationMiddleware::class,
            new Http\Middleware\CmsApiContentNegotiationMiddleware(),
        );

        // Public rate limit middleware
        if ($container->has(TaggedCacheInterface::class)) {
            /** @var TaggedCacheInterface $taggedCache */
            $taggedCache = $container->get(TaggedCacheInterface::class);

            $container->instance(
                Http\Middleware\CmsPublicRateLimitMiddleware::class,
                new Http\Middleware\CmsPublicRateLimitMiddleware($taggedCache, $config),
            );

            // Full-page cache for public content routes. The stampede lock
            // reuses the default pool's lock backend; the session cookie name
            // lets the middleware bypass visitors with server-side state.
            $pageCacheLock = $container->has(CacheManagerInterface::class)
                ? $container->get(CacheManagerInterface::class)->lock()
                : null;

            $sessionCookieName = $container->has(SessionConfig::class)
                ? $container->get(SessionConfig::class)->effectiveCookieName()
                : null;

            $container->instance(
                Http\Middleware\CmsPageCacheMiddleware::class,
                new Http\Middleware\CmsPageCacheMiddleware(
                    cache: $taggedCache,
                    cacheConfig: $config->cache,
                    lock: $pageCacheLock,
                    sessionCookieName: $sessionCookieName,
                ),
            );
        }

        // CMS notification dispatcher
        if ($config->notifications->enabled) {
            $container->instance(
                CmsNotificationDispatcher::class,
                new CmsNotificationDispatcher($config, $logger),
            );
        }

        // AI content assistant (conditional on config)
        $this->bindAiServices($container, $config);

        // Multi-channel publishing stack
        $this->bindPublishingStack($container, $config, $translationRepository, $disk, $logger);

        // Form submission pipeline
        $this->bindFormSubmissionServices($container, $config, $logger);

        // Newsletter stack
        $this->bindNewsletterServices($container, $auditLogger);

        // Documentation services
        $this->bindDocServices($container);

        // Responsive image renderer
        $container->instance(
            ResponsiveImageRenderer::class,
            new ResponsiveImageRenderer(),
        );

        // Cache decorators: wrap repository/service bindings when cache is available
        $this->bindCacheDecorators($container);
    }

    private function bindCacheDecorators(ContainerInterface $container): void
    {
        if (!$container->has(TaggedCacheInterface::class)) {
            return;
        }

        /** @var TaggedCacheInterface $taggedCache */
        $taggedCache = $container->get(TaggedCacheInterface::class);

        // Cached settings
        if ($container->has(SettingsServiceInterface::class)) {
            /** @var SettingsServiceInterface $innerSettings */
            $innerSettings = $container->get(SettingsServiceInterface::class);
            $container->instance(
                SettingsServiceInterface::class,
                new CachedSettingsService($innerSettings, $taggedCache),
            );
        }

        // Cached content
        if ($container->has(ContentRepositoryInterface::class)) {
            /** @var ContentRepositoryInterface $innerContent */
            $innerContent = $container->get(ContentRepositoryInterface::class);
            $container->instance(
                ContentRepositoryInterface::class,
                new CachedContentRepository($innerContent, $taggedCache),
            );
        }

        // Cached menus
        if ($container->has(MenuRepositoryInterface::class)) {
            /** @var MenuRepositoryInterface $innerMenu */
            $innerMenu = $container->get(MenuRepositoryInterface::class);
            $container->instance(
                MenuRepositoryInterface::class,
                new CachedMenuRepository($innerMenu, $taggedCache),
            );
        }

        // Cache invalidator
        $container->instance(
            CmsCacheInvalidator::class,
            new CmsCacheInvalidator($taggedCache),
        );
    }

    private function bindAiServices(ContainerInterface $container, CmsConfig $config): void
    {
        if (!$config->ai->enabled || $config->ai->apiKey === '') {
            return;
        }

        $aiConfig = $config->ai;

        // Use the core AI SDK client if already registered, otherwise create one
        // from CMS config for backward compatibility.
        if ($container->has(AiClientInterface::class)) {
            /** @var AiClientInterface $aiClient */
            $aiClient = $container->get(AiClientInterface::class);
        } else {
            $aiClient = match ($aiConfig->provider) {
                'anthropic' => new CoreAnthropicProvider(
                    apiKey: $aiConfig->apiKey,
                    model: $aiConfig->model !== '' ? $aiConfig->model : 'claude-sonnet-4-6',
                    baseUrl: $aiConfig->baseUrl !== '' ? $aiConfig->baseUrl : 'https://api.anthropic.com/v1',
                ),
                default => new CoreOpenAiProvider(
                    apiKey: $aiConfig->apiKey,
                    model: $aiConfig->model !== '' ? $aiConfig->model : 'gpt-4o',
                    baseUrl: $aiConfig->baseUrl !== '' ? $aiConfig->baseUrl : 'https://api.openai.com/v1',
                ),
            };

            $container->instance(AiClientInterface::class, $aiClient);
        }

        $promptRegistry = new AI\PromptTemplateRegistry();
        CmsPromptTemplates::registerDefaults($promptRegistry);
        $container->instance(AI\PromptTemplateRegistry::class, $promptRegistry);

        $assistant = new ContentAssistant($aiClient, $promptRegistry);
        $container->instance(ContentAssistant::class, $assistant);

        $parser = new AiRequestParser($config);

        $container->instance(
            Http\Controller\Api\AiAssistantApiController::class,
            new Http\Controller\Api\AiAssistantApiController($assistant, $parser),
        );
    }

    private function bindPublishingStack(
        ContainerInterface $container,
        CmsConfig $config,
        ContentTranslationRepositoryInterface $translationRepository,
        MediaDiskInterface $disk,
        LoggerInterface $logger,
    ): void {
        $publishingConfig = $config->publishing;

        // Channel registry (mutable; extensions can add channels during boot)
        $channelRegistry = new ChannelRegistry();
        $container->instance(ChannelRegistry::class, $channelRegistry);

        // Web channel (always enabled)
        $channelRegistry->register(new WebChannel());

        // RSS channel (conditional on config)
        if ($container->has(FeedGeneratorInterface::class)) {
            /** @var FeedGeneratorInterface $feedGenerator */
            $feedGenerator = $container->get(FeedGeneratorInterface::class);

            $channelRegistry->register(new RssChannel(
                $feedGenerator,
                $disk,
                $publishingConfig,
                $config->defaultLocale,
            ));
        }

        // Static site channel (conditional on config)
        $channelRegistry->register(new StaticSiteChannel(
            $publishingConfig->staticSiteOutputPath,
            $publishingConfig->staticSiteEnabled,
        ));

        // Queue driver (optional; enables async publishing)
        /** @var QueueDriverInterface|null $queueDriver */
        $queueDriver = $container->has(QueueDriverInterface::class)
            ? $container->get(QueueDriverInterface::class)
            : null;

        // Publishing orchestrator
        $orchestrator = new PublishingOrchestrator(
            $channelRegistry,
            $translationRepository,
            $queueDriver,
            $logger,
        );
        $container->instance(PublishingOrchestrator::class, $orchestrator);
    }

    private function bindImportExportServices(
        ContainerInterface $container,
        ConnectionInterface $connection,
        CmsConfig $config,
        ContentRepositoryInterface $contentRepository,
        ContentTranslationRepositoryInterface $translationRepository,
        RedirectRepositoryInterface $redirectRepository,
        MediaRepositoryInterface $mediaRepository,
        MediaDiskInterface $disk,
        ?AuditLoggerInterface $auditLogger,
    ): void {
        /** @var TaxonomyRepositoryInterface $taxonomyRepository */
        $taxonomyRepository = $container->get(TaxonomyRepositoryInterface::class);

        /** @var MenuRepositoryInterface $menuRepository */
        $menuRepository = $container->get(MenuRepositoryInterface::class);

        $importConfig = $config->import;

        // Export bundle generator
        $settingsService = $container->has(SettingsServiceInterface::class)
            ? $container->get(SettingsServiceInterface::class)
            : null;

        if ($settingsService !== null) {
            /** @var SettingsServiceInterface $settingsService */
            $commentRepository = $container->has(CommentRepositoryInterface::class)
                ? $container->get(CommentRepositoryInterface::class)
                : null;

            $userRepository = $container->has(CmsUserRepositoryInterface::class)
                ? $container->get(CmsUserRepositoryInterface::class)
                : null;

            /** @var ?CommentRepositoryInterface $commentRepository */
            /** @var ?CmsUserRepositoryInterface $userRepository */
            $exportGenerator = new ExportBundleGenerator(
                $contentRepository,
                $taxonomyRepository,
                $menuRepository,
                $settingsService,
                $mediaRepository,
                $commentRepository,
                $userRepository,
                $auditLogger,
            );

            /** @var ContentBlockRepositoryInterface $blockRepository */
            $blockRepository = $container->get(ContentBlockRepositoryInterface::class);

            $importParser = new ImportParser(
                $contentRepository,
                $translationRepository,
                $blockRepository,
                $taxonomyRepository,
                $menuRepository,
                $settingsService,
                $importConfig,
                $auditLogger,
            );

            /** @var TaxonomyServiceInterface $taxonomyService */
            $taxonomyService = $container->get(TaxonomyServiceInterface::class);

            /** @var MediaServiceInterface $mediaService */
            $mediaService = $container->get(MediaServiceInterface::class);

            /** @var SitemapGeneratorInterface $sitemapGenerator */
            $sitemapGenerator = $container->get(SitemapGeneratorInterface::class);

            /** @var SafeHttpClient $httpClient */
            $httpClient = $container->get(SafeHttpClient::class);

            // Use lazy binding so ImportExportRegistry (created after register phase)
            // is resolved at first use rather than at construction time.
            // Both SiteDefinitionParser and ImportExportService need the registry:
            // SiteDefinitionParser delegates forum data to the forum import provider.
            $container->bind(
                ImportExportServiceInterface::class,
                static function () use ($container, $contentRepository, $translationRepository, $taxonomyService, $taxonomyRepository, $menuRepository, $mediaService, $settingsService, $redirectRepository, $sitemapGenerator, $httpClient, $importConfig, $auditLogger, $exportGenerator, $importParser): ImportExportService {
                    /** @var \Pulsar\ImportExport\ImportExportRegistry|null $registry */
                    $registry = $container->has(\Pulsar\ImportExport\ImportExportRegistry::class)
                        ? $container->get(\Pulsar\ImportExport\ImportExportRegistry::class)
                        : null;

                    $siteDefinitionParser = new SiteDefinitionParser(
                        $contentRepository,
                        $taxonomyService,
                        $taxonomyRepository,
                        $menuRepository,
                        $mediaService,
                        $settingsService,
                        $redirectRepository,
                        $sitemapGenerator,
                        $httpClient,
                        $importConfig,
                        $auditLogger,
                        $registry,
                        $translationRepository,
                    );

                    return new ImportExportService($exportGenerator, $importParser, $siteDefinitionParser, $registry);
                },
            );

            // Media bundle exporter (ZIP with media files)
            $container->instance(
                MediaBundleExporterInterface::class,
                new MediaBundleExporter(
                    $exportGenerator,
                    $mediaRepository,
                    $disk,
                    $auditLogger,
                ),
            );

            // Import analyzer (pre-import analysis)
            $container->instance(
                ImportAnalyzer::class,
                new ImportAnalyzer(
                    $contentRepository,
                    $mediaRepository,
                ),
            );

            // Media bundle importer (ZIP + JSON with duplicate resolution)
            $container->instance(
                MediaBundleImporter::class,
                new MediaBundleImporter(
                    $importParser,
                    $mediaRepository,
                    $disk,
                    $auditLogger,
                ),
            );
        }

        // Backup service
        $container->instance(
            BackupServiceInterface::class,
            new BackupService($connection, $disk, $auditLogger),
        );
    }

    private function bindFormSubmissionServices(
        ContainerInterface $container,
        CmsConfig $config,
        LoggerInterface $logger,
    ): void {
        if (!$container->has(FormSubmissionRepositoryInterface::class)) {
            return;
        }

        /** @var FormSubmissionRepositoryInterface $formRepo */
        $formRepo = $container->get(FormSubmissionRepositoryInterface::class);

        // Build spam scorer with all available detectors
        $formsConfig = $config->forms;

        $spamScorer = new SpamScorer($formsConfig->spamThreshold);
        $spamScorer->addDetector(new HoneypotDetector($formsConfig->honeypotFieldName));
        $spamScorer->addDetector(new TimingDetector());
        $spamScorer->addDetector(new ContentHeuristicScorer());

        // Managed challenge (signed, single-use, TTL-bound proof of work). Added
        // only when the 'managed' captcha provider is configured and the core
        // anti-spam wiring exposed the verifier; otherwise the form relies on the
        // remaining honeypot/timing/content/rate-limit detectors.
        if ($container->has(CaptchaVerifierInterface::class)) {
            /** @var CaptchaVerifierInterface $captchaVerifier */
            $captchaVerifier = $container->get(CaptchaVerifierInterface::class);
            $spamScorer->addDetector(new ManagedChallengeDetector($captchaVerifier));
        }

        // Rate limiter (needs cache)
        if ($container->has(CacheManagerInterface::class)) {
            /** @var CacheManagerInterface $cacheManager */
            $cacheManager = $container->get(CacheManagerInterface::class);
            $spamScorer->addDetector(new RateLimitDetector(
                $cacheManager->simple(),
                $formsConfig->rateLimitPerHour,
            ));
        }

        $container->instance(SpamScorer::class, $spamScorer);

        // Form submission service
        if (
            $container->has(MailManager::class)
            && $container->has(EventDispatcherInterface::class)
            && $container->has(CsrfTokenManagerInterface::class)
        ) {
            /** @var MailManager $mailManager */
            $mailManager = $container->get(MailManager::class);

            /** @var EventDispatcherInterface $eventDispatcher */
            $eventDispatcher = $container->get(EventDispatcherInterface::class);

            /** @var CsrfTokenManagerInterface $csrfManager */
            $csrfManager = $container->get(CsrfTokenManagerInterface::class);

            /** @var MetricRegistry|null $metricRegistry */
            $metricRegistry = $container->has(MetricRegistry::class)
                ? $container->get(MetricRegistry::class)
                : null;

            $formService = new FormSubmissionService(
                $formRepo,
                $spamScorer,
                $mailManager,
                $eventDispatcher,
                $csrfManager,
                $logger,
                $formsConfig->notificationRecipients,
                $metricRegistry,
            );

            $container->instance(FormSubmissionServiceInterface::class, $formService);

            // Public form submission controller
            $container->instance(
                PublicFormSubmissionController::class,
                new PublicFormSubmissionController($formService),
            );
        }
    }

    private function bindNewsletterServices(
        ContainerInterface $container,
        ?AuditLoggerInterface $auditLogger,
    ): void {
        if (
            $auditLogger === null
            || !$container->has(NewsletterSubscriberRepositoryInterface::class)
            || !$container->has(NewsletterCampaignRepositoryInterface::class)
            || !$container->has(MailManagerInterface::class)
        ) {
            return;
        }

        /** @var NewsletterSubscriberRepositoryInterface $subscriberRepo */
        $subscriberRepo = $container->get(NewsletterSubscriberRepositoryInterface::class);

        /** @var NewsletterCampaignRepositoryInterface $campaignRepo */
        $campaignRepo = $container->get(NewsletterCampaignRepositoryInterface::class);

        /** @var MailManagerInterface $mailManager */
        $mailManager = $container->get(MailManagerInterface::class);

        $container->instance(
            NewsletterSubscriptionServiceInterface::class,
            new NewsletterSubscriptionService($subscriberRepo, $mailManager, $auditLogger),
        );

        $container->instance(
            CampaignEditorServiceInterface::class,
            new CampaignEditorService($campaignRepo, $subscriberRepo, $mailManager, $auditLogger),
        );
    }

    private function bindDocServices(ContainerInterface $container): void
    {
        if (!$container->has(DbDocVersionRepository::class)) {
            return;
        }

        /** @var DbDocVersionRepository $docVersionRepo */
        $docVersionRepo = $container->get(DbDocVersionRepository::class);

        $container->instance(
            DocVersionServiceInterface::class,
            new DocVersionService($docVersionRepo),
        );
    }
}
