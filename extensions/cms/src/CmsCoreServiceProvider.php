<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\ABTest\ExperimentRepositoryInterface;
use Pulsar\Extension\Cms\AI\ContentAssistant;
use Pulsar\Extension\Cms\AI\LlmProviderInterface;
use Pulsar\Extension\Cms\BlockEditor\BlockRenderer;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeRegistry;
use Pulsar\Extension\Cms\Collaboration\CollaborationRepositoryInterface;
use Pulsar\Extension\Cms\Comments\CommentBodyPolicy;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Comments\CommentService;
use Pulsar\Extension\Cms\Comments\CommentServiceInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Content\RevisionService;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Http\Controller\Api\CollaborationApiController;
use Pulsar\Extension\Cms\Internal\ABTest\ExperimentService;
use Pulsar\Extension\Cms\Internal\ABTest\TrafficSplitter;
use Pulsar\Extension\Cms\Internal\AI\AnthropicProvider;
use Pulsar\Extension\Cms\Internal\AI\OpenAiProvider;
use Pulsar\Extension\Cms\Internal\Cache\CachedContentRepository;
use Pulsar\Extension\Cms\Internal\Cache\CachedMenuRepository;
use Pulsar\Extension\Cms\Internal\Cache\CachedSettingsService;
use Pulsar\Extension\Cms\Internal\Cache\CmsCacheInvalidator;
use Pulsar\Extension\Cms\Internal\Collaboration\CollaborationService as CollaborationServiceImpl;
use Pulsar\Extension\Cms\Internal\Notification\CmsNotificationDispatcher;
use Pulsar\Extension\Cms\Internal\Publishing\PublishingOrchestrator;
use Pulsar\Extension\Cms\Internal\Publishing\RssChannel;
use Pulsar\Extension\Cms\Internal\Publishing\StaticSiteChannel;
use Pulsar\Extension\Cms\Internal\Publishing\WebChannel;
use Pulsar\Extension\Cms\Internal\Search\SearchServiceFactory;
use Pulsar\Extension\Cms\Internal\Security\ClientFingerprintResolver;
use Pulsar\Extension\Cms\Internal\Security\CmsKeyManager;
use Pulsar\Extension\Cms\Internal\Security\QrCodeEncoder;
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
use Pulsar\Extension\Cms\Internal\Tools\SiteDefinitionParser;
use Pulsar\Extension\Cms\Internal\Tools\ToolsService;
use Pulsar\Extension\Cms\Media\ImageProcessor;
use Pulsar\Extension\Cms\Media\ImageProcessorInterface;
use Pulsar\Extension\Cms\Media\LocalDisk;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaService;
use Pulsar\Extension\Cms\Media\MediaServiceInterface;
use Pulsar\Extension\Cms\Media\Security\FilenameSanitizer;
use Pulsar\Extension\Cms\Media\Security\FileValidator;
use Pulsar\Extension\Cms\Media\Security\PdfValidator;
use Pulsar\Extension\Cms\Media\Security\SvgSanitizer;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGeneratorInterface;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Publishing\ChannelRegistry;
use Pulsar\Extension\Cms\Search\SearchAnalyticsRepositoryInterface;
use Pulsar\Extension\Cms\Search\SearchServiceInterface;
use Pulsar\Extension\Cms\Seo\FeedGeneratorInterface;
use Pulsar\Extension\Cms\Seo\LinkHealthRepositoryInterface;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;
use Pulsar\Extension\Cms\Seo\RedirectManagerInterface;
use Pulsar\Extension\Cms\Seo\RobotsTxtGeneratorInterface;
use Pulsar\Extension\Cms\Seo\SeoServiceInterface;
use Pulsar\Extension\Cms\Seo\SitemapGeneratorInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyService;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyServiceInterface;
use Pulsar\Extension\Cms\Tools\BackupServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ToolsServiceInterface;
use Pulsar\Extension\Cms\Workflow\ContentLockService;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Security\Crypto\MasterKey;

/**
 * Binds CMS core services: settings, taxonomy, media, comments, search, SEO,
 * navigation, i18n, editorial workflow, tools/backup, security, block editor,
 * and content controller.
 */
#[Internal(reason: 'CMS service wiring — use interfaces for public API')]
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
                new \Pulsar\Extension\Cms\Settings\SettingsService($connection, $auditLogger),
            );
        }

        // Taxonomy service
        $container->instance(
            TaxonomyServiceInterface::class,
            new TaxonomyService($connection),
        );

        // Block editor registry and renderer (mutable singleton — blocks registered during boot)
        $blockTypeRegistry = new BlockTypeRegistry();
        $container->instance(BlockTypeRegistry::class, $blockTypeRegistry);
        $container->instance(BlockRenderer::class, new BlockRenderer($blockTypeRegistry));

        // Media stack
        $disk = new LocalDisk($config->media->storagePath);
        $container->instance(MediaDiskInterface::class, $disk);

        $imageProcessor = new ImageProcessor($config->media);
        $container->instance(ImageProcessorInterface::class, $imageProcessor);

        $fileValidator = new FileValidator($config->media);
        $filenameSanitizer = new FilenameSanitizer();
        $svgSanitizer = new SvgSanitizer();
        $pdfValidator = new PdfValidator();

        /** @var MediaRepositoryInterface $mediaRepository */
        $mediaRepository = $container->get(MediaRepositoryInterface::class);

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
            ),
        );

        // Comment stack
        /** @var CommentRepositoryInterface $commentRepository */
        $commentRepository = $container->get(CommentRepositoryInterface::class);

        /** @var ContentRepositoryInterface $contentRepository */
        $contentRepository = $container->get(ContentRepositoryInterface::class);

        if (!$container->has(SafeHtmlPolicy::class) && $auditLogger !== null) {
            $container->instance(SafeHtmlPolicy::class, new SafeHtmlPolicy($auditLogger));
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

        // SEO stack — structured data generators
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

        $container->instance(
            RobotsTxtGeneratorInterface::class,
            new RobotsTxtGenerator(),
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
        }

        // Editorial workflow service
        if ($auditLogger !== null) {
            $workflowService = new \Pulsar\Extension\Cms\Workflow\EditorialWorkflowService(
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
        $breadcrumbGenerator = new \Pulsar\Extension\Cms\Navigation\BreadcrumbGenerator(
            $contentRepository,
            $translationRepository,
            $config,
        );
        $container->instance(BreadcrumbGeneratorInterface::class, $breadcrumbGenerator);

        // I18n stack
        $localeResolver = new \Pulsar\Extension\Cms\I18n\LocaleResolver();
        $hreflangGenerator = new \Pulsar\Extension\Cms\I18n\HreflangGenerator(
            $translationRepository,
            $localeResolver,
        );
        $container->instance(\Pulsar\Extension\Cms\I18n\HreflangGenerator::class, $hreflangGenerator);

        // Content controller (front-office)
        /** @var ContentBlockRepositoryInterface $blockRepository */
        $blockRepository = $container->get(ContentBlockRepositoryInterface::class);

        /** @var FieldRegistryRepositoryInterface $fieldRepository */
        $fieldRepository = $container->get(FieldRegistryRepositoryInterface::class);

        /** @var CmsKeyManager $cmsKeyManager */
        $cmsKeyManager = $container->get(CmsKeyManager::class);

        /** @var SeoServiceInterface $seoService */
        $seoService = $container->get(SeoServiceInterface::class);

        $container->instance(
            Http\Controller\ContentController::class,
            new Http\Controller\ContentController(
                $contentRepository,
                $translationRepository,
                $blockRepository,
                $redirectRepository,
                $fieldRepository,
                $breadcrumbGenerator,
                $hreflangGenerator,
                $cmsKeyManager,
                $safeHtmlPolicy,
                $config,
                $seoService,
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

        // Cache decorators — wrap repository/service bindings when cache is available
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

        $provider = match ($aiConfig->provider) {
            'anthropic' => new AnthropicProvider(
                apiKey: $aiConfig->apiKey,
                model: $aiConfig->model !== '' ? $aiConfig->model : 'claude-sonnet-4-6',
                baseUrl: $aiConfig->baseUrl !== '' ? $aiConfig->baseUrl : 'https://api.anthropic.com/v1',
            ),
            default => new OpenAiProvider(
                apiKey: $aiConfig->apiKey,
                model: $aiConfig->model !== '' ? $aiConfig->model : 'gpt-4o',
                baseUrl: $aiConfig->baseUrl !== '' ? $aiConfig->baseUrl : 'https://api.openai.com/v1',
            ),
        };

        $container->instance(LlmProviderInterface::class, $provider);

        $promptRegistry = new AI\PromptTemplateRegistry();
        Internal\AI\CmsPromptTemplates::registerDefaults($promptRegistry);
        $container->instance(AI\PromptTemplateRegistry::class, $promptRegistry);

        $assistant = new ContentAssistant($provider, $promptRegistry);
        $container->instance(ContentAssistant::class, $assistant);

        $parser = new Internal\Http\AiRequestParser($config);

        $container->instance(
            Http\Controller\Api\AiAssistantApiController::class,
            new Http\Controller\Api\AiAssistantApiController($assistant, $config, $parser),
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

        // Channel registry (mutable — extensions can add channels during boot)
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

        // Queue driver (optional — enables async publishing)
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
            $exportGenerator = new ExportBundleGenerator(
                $contentRepository,
                $taxonomyRepository,
                $menuRepository,
                $settingsService,
                $mediaRepository,
                $auditLogger,
            );

            $importParser = new ImportParser(
                $contentRepository,
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
            );

            $container->instance(
                ImportExportServiceInterface::class,
                new ImportExportService($exportGenerator, $importParser, $siteDefinitionParser),
            );
        }

        // Backup service
        $container->instance(
            BackupServiceInterface::class,
            new BackupService($connection, $disk, $auditLogger),
        );
    }
}
