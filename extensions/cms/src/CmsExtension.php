<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Event\ListenerProviderInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\Event\CmsReady;
use Pulsar\Extension\Cms\Content\Event\CommentReceived;
use Pulsar\Extension\Cms\Content\Event\ContentPublished;
use Pulsar\Extension\Cms\Content\Event\ReviewRequested;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\Http\Controller\AccountController;
use Pulsar\Extension\Cms\Http\Controller\Admin\AssetController;
use Pulsar\Extension\Cms\Http\Controller\Admin\BackupController;
use Pulsar\Extension\Cms\Http\Controller\Admin\BulkOperationsController;
use Pulsar\Extension\Cms\Http\Controller\Admin\CommentController as AdminCommentController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ContentController as AdminContentController;
use Pulsar\Extension\Cms\Http\Controller\Admin\DashboardController as AdminDashboardController;
use Pulsar\Extension\Cms\Http\Controller\Admin\DigitalAssetController;
use Pulsar\Extension\Cms\Http\Controller\Admin\DocVersionController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ExperimentController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ExportController;
use Pulsar\Extension\Cms\Http\Controller\Admin\FieldController;
use Pulsar\Extension\Cms\Http\Controller\Admin\FormSubmissionController as AdminFormSubmissionController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ImportController;
use Pulsar\Extension\Cms\Http\Controller\Admin\InvoiceController;
use Pulsar\Extension\Cms\Http\Controller\Admin\LinkHealthController;
use Pulsar\Extension\Cms\Http\Controller\Admin\LiveCssController;
use Pulsar\Extension\Cms\Http\Controller\Admin\MediaController as AdminMediaController;
use Pulsar\Extension\Cms\Http\Controller\Admin\MenuController as AdminMenuController;
use Pulsar\Extension\Cms\Http\Controller\Admin\NewsletterController as AdminNewsletterController;
use Pulsar\Extension\Cms\Http\Controller\Admin\OrderController;
use Pulsar\Extension\Cms\Http\Controller\Admin\PluginController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ProductController;
use Pulsar\Extension\Cms\Http\Controller\Admin\PromotionController;
use Pulsar\Extension\Cms\Http\Controller\Admin\RateLimitDashboardController;
use Pulsar\Extension\Cms\Http\Controller\Admin\RedirectController as AdminRedirectController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ReviewController;
use Pulsar\Extension\Cms\Http\Controller\Admin\RevisionController;
use Pulsar\Extension\Cms\Http\Controller\Admin\RobotsController;
use Pulsar\Extension\Cms\Http\Controller\Admin\SearchAnalyticsController;
use Pulsar\Extension\Cms\Http\Controller\Admin\SettingsController;
use Pulsar\Extension\Cms\Http\Controller\Admin\SiteDefinitionController;
use Pulsar\Extension\Cms\Http\Controller\Admin\SitemapController as AdminSitemapController;
use Pulsar\Extension\Cms\Http\Controller\Admin\TaxonomyController as AdminTaxonomyController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ThemeController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ToolsController;
use Pulsar\Extension\Cms\Http\Controller\Admin\TwoFactorController;
use Pulsar\Extension\Cms\Http\Controller\Admin\UserController;
use Pulsar\Extension\Cms\Http\Controller\Api\AiAssistantApiController;
use Pulsar\Extension\Cms\Http\Controller\Api\CollaborationApiController;
use Pulsar\Extension\Cms\Http\Controller\Api\CommentApiController;
use Pulsar\Extension\Cms\Http\Controller\Api\CommerceApiController;
use Pulsar\Extension\Cms\Http\Controller\Api\ContentApiController;
use Pulsar\Extension\Cms\Http\Controller\Api\DocFeedbackController;
use Pulsar\Extension\Cms\Http\Controller\Api\FeedController;
use Pulsar\Extension\Cms\Http\Controller\Api\MediaApiController;
use Pulsar\Extension\Cms\Http\Controller\Api\NewsletterApiController;
use Pulsar\Extension\Cms\Http\Controller\Api\NewsletterFeedController;
use Pulsar\Extension\Cms\Http\Controller\Api\TaxonomyApiController;
use Pulsar\Extension\Cms\Http\Controller\CheckoutController;
use Pulsar\Extension\Cms\Http\Controller\ContentController;
use Pulsar\Extension\Cms\Http\Controller\DigitalDownloadController;
use Pulsar\Extension\Cms\Http\Controller\FormSubmissionController;
use Pulsar\Extension\Cms\Http\Controller\MediaController;
use Pulsar\Extension\Cms\Http\Controller\Newsletter\BounceWebhookController;
use Pulsar\Extension\Cms\Http\Controller\Newsletter\TrackingController;
use Pulsar\Extension\Cms\Http\Controller\Newsletter\UnsubscribeController;
use Pulsar\Extension\Cms\Http\Controller\ResumePdfController;
use Pulsar\Extension\Cms\Http\Controller\SeoController;
use Pulsar\Extension\Cms\Http\Controller\SitemapController;
use Pulsar\Extension\Cms\Http\Controller\WebhookController;
use Pulsar\Extension\Cms\Http\Middleware\CmsLocaleMiddleware;
use Pulsar\Extension\Cms\Http\Middleware\CmsPageCacheMiddleware;
use Pulsar\Extension\Cms\ImportExport\CmsImportExportProvider;
use Pulsar\Extension\Cms\Internal\Notification\CmsNotificationDispatcher;
use Pulsar\Extension\Cms\Internal\Scheduler\BackupRetentionJob;
use Pulsar\Extension\Cms\Internal\Scheduler\ExpiredSessionCleanupJob;
use Pulsar\Extension\Cms\Internal\Scheduler\LinkHealthCheckJob;
use Pulsar\Extension\Cms\Internal\Scheduler\ScheduledPublishingJob;
use Pulsar\Extension\Cms\Internal\Scheduler\SearchAnalyticsCleanupJob;
use Pulsar\Extension\Cms\Internal\Scheduler\WebhookRetryCleanupJob;
use Pulsar\Extension\Cms\Internal\Studio\CmsAuditPanel;
use Pulsar\Extension\Cms\Internal\Studio\CmsStudioModule;
use Pulsar\Extension\Cms\Internal\Studio\ContentCacheInspectorPanel;
use Pulsar\Extension\Cms\Internal\Studio\MediaProcessingQueuePanel;
use Pulsar\Extension\Cms\Internal\Studio\SeoHealthReportPanel;
use Pulsar\Extension\Cms\Media\Security\HotlinkProtectionMiddleware;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGenerator;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGeneratorInterface;
use Pulsar\Extension\Cms\Search\SearchAnalyticsRepositoryInterface;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Themes\PreviewSessionRepositoryInterface;
use Pulsar\Extension\Cms\Tools\BackupServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;
use Pulsar\Extension\Studio\Contracts\StudioModuleRegistryInterface;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\ImportExport\ImportExportRegistry;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Security\AntiSpam\ManagedChallenge\ManagedChallengeRenderer;
use Pulsar\Security\Audit\AuditChainVerifier;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * CMS extension for regulated, mission-critical domains.
 *
 * Provides content management, taxonomy, navigation, editorial workflow,
 * custom fields, content locking, event sourcing, atomic snapshots,
 * safe HTML sanitization, and full-page caching with tag-based invalidation.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CmsExtension implements ExtensionInterface, PreBootExtensionInterface, PostBootExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/cms';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Service provider (CmsServiceProvider) handles all bindings:
        // - Repository bindings (raw-DB-backed implementations)
        // - CMS permissions registration with RoleRegistryInterface
        // The provider is listed in providers() and invoked by the framework.
    }

    #[Override]
    public function preBoot(ContainerInterface $container): void
    {
        // Load CMS config from file unless already pre-registered (e.g. by dev router)
        if (!$container->has(CmsConfig::class) && $container->has(ConfigManagerInterface::class)) {
            /** @var ConfigManagerInterface $configManager */
            $configManager = $container->get(ConfigManagerInterface::class);
            $configPath = $configManager->configPath();

            if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'cms.php')) {
                /**
                 * @psalm-suppress UnresolvableInclude
                 * @var mixed $cmsData
                 */
                $cmsData = require $configPath . DIRECTORY_SEPARATOR . 'cms.php';

                if (is_array($cmsData)) {
                    /** @var array<string, mixed> $cmsData */
                    $cmsConfig = CmsConfig::fromArray($cmsData);
                    $container->instance(CmsConfig::class, $cmsConfig);
                }
            }
        }

        if (!$container->has(CmsConfig::class)) {
            $container->instance(CmsConfig::class, CmsConfig::fromArray([]));
        }

        // Bind BreadcrumbGeneratorInterface if dependencies are available
        if (
            !$container->has(BreadcrumbGeneratorInterface::class)
            && $container->has(ContentRepositoryInterface::class)
            && $container->has(ContentTranslationRepositoryInterface::class)
        ) {
            $container->instance(BreadcrumbGeneratorInterface::class, new BreadcrumbGenerator(
                $container->get(ContentRepositoryInterface::class),
                $container->get(ContentTranslationRepositoryInterface::class),
                $container->get(CmsConfig::class),
            ));
        }

        // Register as a Studio module when all required dependencies are available
        $this->registerStudioModule($container);
    }

    private function registerStudioModule(ContainerInterface $container): void
    {
        if (!$container->has(StudioModuleRegistryInterface::class)) {
            return;
        }

        if (
            !$container->has(AuditChainVerifier::class)
            || !$container->has(TaggedCacheInterface::class)
            || !$container->has(MetricRegistry::class)
            || !$container->has(QueueDriverInterface::class)
            || !$container->has(LinkHealthServiceInterface::class)
            || !$container->has(ContentRepositoryInterface::class)
        ) {
            return;
        }

        /** @var StudioModuleRegistryInterface $studioRegistry */
        $studioRegistry = $container->get(StudioModuleRegistryInterface::class);

        /** @var AuditChainVerifier $chainVerifier */
        $chainVerifier = $container->get(AuditChainVerifier::class);

        /** @var TaggedCacheInterface $taggedCache */
        $taggedCache = $container->get(TaggedCacheInterface::class);

        /** @var MetricRegistry $metricRegistry */
        $metricRegistry = $container->get(MetricRegistry::class);

        /** @var QueueDriverInterface $queueDriver */
        $queueDriver = $container->get(QueueDriverInterface::class);

        /** @var LinkHealthServiceInterface $linkHealthService */
        $linkHealthService = $container->get(LinkHealthServiceInterface::class);

        $studioRegistry->register(new CmsStudioModule(
            auditPanel: new CmsAuditPanel($chainVerifier),
            cachePanel: new ContentCacheInspectorPanel($taggedCache, $metricRegistry),
            mediaPanel: new MediaProcessingQueuePanel($queueDriver),
            seoPanel: new SeoHealthReportPanel($linkHealthService),
        ));
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        /** @var CmsConfig $config */
        $config = $container->get(CmsConfig::class);

        // Wire all framework security, performance, and detection middleware
        if ($container->has(MiddlewareRegistry::class)) {
            /** @var MiddlewareRegistry $middlewareRegistry */
            $middlewareRegistry = $container->get(MiddlewareRegistry::class);
            new CmsSecurityIntegration()->register($container, $middlewareRegistry);
        }

        $this->registerCoreBlockTypes($container);
        $this->registerApiRoutes($router, $config);
        $this->registerPublicRoutes($router, $config, $container->has(CmsPageCacheMiddleware::class));
        $this->registerAdminRoutes($router, $container);
    }

    private function registerCoreBlockTypes(ContainerInterface $container): void
    {
        if (!$container->has(BlockEditor\BlockTypeRegistry::class)) {
            return;
        }

        /** @var BlockEditor\BlockTypeRegistry $registry */
        $registry = $container->get(BlockEditor\BlockTypeRegistry::class);

        $registry->register(new BlockEditor\CoreBlocks\ParagraphBlock());
        $registry->register(new BlockEditor\CoreBlocks\HeadingBlock());

        /** @var Media\ResponsiveImageRenderer|null $responsiveRenderer */
        $responsiveRenderer = $container->has(Media\ResponsiveImageRenderer::class)
            ? $container->get(Media\ResponsiveImageRenderer::class)
            : null;

        $registry->register(new BlockEditor\CoreBlocks\ImageBlock($responsiveRenderer));
        $registry->register(new BlockEditor\CoreBlocks\GalleryBlock($responsiveRenderer));
        $registry->register(new BlockEditor\CoreBlocks\CodeBlock());
        $registry->register(new BlockEditor\CoreBlocks\EmbedBlock());
        $registry->register(new BlockEditor\CoreBlocks\QuoteBlock());
        $registry->register(new BlockEditor\CoreBlocks\ListBlock());
        $registry->register(new BlockEditor\CoreBlocks\TableBlock());
        $registry->register(new BlockEditor\CoreBlocks\CtaBlock());

        // Layout & Structure
        $registry->register(new BlockEditor\CoreBlocks\SeparatorBlock());
        $registry->register(new BlockEditor\CoreBlocks\SpacerBlock());
        if ($container->has(SafeHtmlPolicy::class)) {
            /** @var SafeHtmlPolicy $safeHtmlPolicy */
            $safeHtmlPolicy = $container->get(SafeHtmlPolicy::class);
            $registry->register(new BlockEditor\CoreBlocks\HtmlBlock($safeHtmlPolicy));
        }
        $registry->register(new BlockEditor\CoreBlocks\ButtonGroupBlock());

        // Media
        $registry->register(new BlockEditor\CoreBlocks\AudioBlock());
        $registry->register(new BlockEditor\CoreBlocks\VideoBlock());
        $registry->register(new BlockEditor\CoreBlocks\FileDownloadBlock());
        $registry->register(new BlockEditor\CoreBlocks\IconBlock());

        // Content Sections
        $registry->register(new BlockEditor\CoreBlocks\HeroBlock());
        $registry->register(new BlockEditor\CoreBlocks\AlertBlock());
        $registry->register(new BlockEditor\CoreBlocks\TestimonialBlock());
        $registry->register(new BlockEditor\CoreBlocks\CounterBlock());
        $registry->register(new BlockEditor\CoreBlocks\ProgressBarBlock());

        // Interactive
        $registry->register(new BlockEditor\CoreBlocks\AccordionBlock());
        $registry->register(new BlockEditor\CoreBlocks\TabsBlock());
        $registry->register(new BlockEditor\CoreBlocks\CarouselBlock());
        $registry->register(new BlockEditor\CoreBlocks\MapBlock());

        // Commerce & Social
        $registry->register(new BlockEditor\CoreBlocks\PricingTableBlock());
        $registry->register(new BlockEditor\CoreBlocks\SocialLinksBlock());
        if ($container->has(CsrfTokenManagerInterface::class)) {
            /** @var CsrfTokenManagerInterface $csrfManager */
            $csrfManager = $container->get(CsrfTokenManagerInterface::class);

            $challengeRenderer = null;

            if ($container->has(ManagedChallengeRenderer::class)) {
                /** @var ManagedChallengeRenderer $challengeRenderer */
                $challengeRenderer = $container->get(ManagedChallengeRenderer::class);
            }

            $registry->register(new BlockEditor\CoreBlocks\ContactFormBlock($csrfManager, $challengeRenderer));
            $registry->register(new BlockEditor\CoreBlocks\LoginFormBlock($csrfManager));
            $registry->register(new BlockEditor\CoreBlocks\CommentsBlock($csrfManager));
        }

        // Comparison
        $registry->register(new BlockEditor\CoreBlocks\CompareBlock());
        $registry->register(new BlockEditor\CoreBlocks\CodeComparisonBlock());

        // Newsletter
        $registry->register(new BlockEditor\CoreBlocks\NewsletterBlock());

        // Documentation
        $registry->register(new BlockEditor\CoreBlocks\DocsBlock());
        $registry->register(new BlockEditor\CoreBlocks\CodeExampleBlock());

        // Showcase
        $registry->register(new BlockEditor\CoreBlocks\ShowcaseBlock());
        $registry->register(new BlockEditor\CoreBlocks\ShowcaseHeroBlock());

        // Resume
        $registry->register(new BlockEditor\CoreBlocks\ResumeBlock());

        // Dynamic Content
        $registry->register(new BlockEditor\CoreBlocks\SearchBlock());
        $registry->register(new BlockEditor\CoreBlocks\TagCloudBlock());
        $registry->register(new BlockEditor\CoreBlocks\CategoriesBlock());
        $registry->register(new BlockEditor\CoreBlocks\LatestPostsBlock());
        $registry->register(new BlockEditor\CoreBlocks\ArchivesBlock());
        $registry->register(new BlockEditor\CoreBlocks\RssBlock());
        $registry->register(new BlockEditor\CoreBlocks\PageListBlock());
        $registry->register(new BlockEditor\CoreBlocks\CalendarBlock());

        // Site & Navigation
        $registry->register(new BlockEditor\CoreBlocks\SiteTitleBlock());
        $registry->register(new BlockEditor\CoreBlocks\NavigationBlock());
        $registry->register(new BlockEditor\CoreBlocks\AvatarBlock());
        $registry->register(new BlockEditor\CoreBlocks\ComplianceBadgeBlock());
        $registry->register(new BlockEditor\CoreBlocks\BenchmarkBlock());

        // Columns (registered last; depends on BlockRenderer which uses the registry)
        if ($container->has(BlockEditor\BlockRenderer::class)) {
            /** @var BlockEditor\BlockRenderer $blockRenderer */
            $blockRenderer = $container->get(BlockEditor\BlockRenderer::class);
            $registry->register(new BlockEditor\CoreBlocks\ColumnsBlock($blockRenderer));
        }
    }

    #[Override]
    public function postBoot(ContainerInterface $container): void
    {
        // Register CMS import/export provider
        $this->registerImportExportProvider($container);

        // Warm critical caches: settings
        if ($container->has(SettingsServiceInterface::class) && $container->has(TaggedCacheInterface::class)) {
            /** @var SettingsServiceInterface $settings */
            $settings = $container->get(SettingsServiceInterface::class);

            /** @var CmsConfig $config */
            $config = $container->get(CmsConfig::class);

            // Pre-load settings for the default locale
            $settings->getAll($config->defaultLocale);
        }

        // Register scheduler jobs for periodic CMS maintenance
        $this->registerSchedulerJobs($container);

        // Register notification event listeners when enabled
        $this->registerNotificationListeners($container);

        // Emit CmsReady event
        if ($container->has(EventDispatcherInterface::class)) {
            /** @var EventDispatcherInterface $dispatcher */
            $dispatcher = $container->get(EventDispatcherInterface::class);
            $dispatcher->dispatch(new CmsReady(new DateTimeImmutable()));
        }
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            CmsServiceProvider::class,
        ];
    }

    private function registerApiRoutes(RouterInterface $router, CmsConfig $config): void
    {
        $prefix = '/api/v1';

        // Content API
        $router->get("$prefix/content", [ContentApiController::class, 'index'], 'cms.api.content.index');
        $router->post("$prefix/content", [ContentApiController::class, 'create'], 'cms.api.content.create');
        $router->get("$prefix/content/{id}", [ContentApiController::class, 'show'], 'cms.api.content.show');
        $router->put("$prefix/content/{id}", [ContentApiController::class, 'update'], 'cms.api.content.update');
        $router->delete("$prefix/content/{id}", [ContentApiController::class, 'delete'], 'cms.api.content.delete');

        // Taxonomy API
        $router->get("$prefix/taxonomies/{slug}", [TaxonomyApiController::class, 'show'], 'cms.api.taxonomies.show');
        $router->get("$prefix/taxonomies/{slug}/terms", [TaxonomyApiController::class, 'terms'], 'cms.api.taxonomies.terms');

        // Media API
        $router->get("$prefix/media", [MediaApiController::class, 'index'], 'cms.api.media.index');
        $router->post("$prefix/media", [MediaApiController::class, 'upload'], 'cms.api.media.upload');
        $router->get("$prefix/media/{id}", [MediaApiController::class, 'show'], 'cms.api.media.show');
        $router->delete("$prefix/media/{id}", [MediaApiController::class, 'delete'], 'cms.api.media.delete');

        // Collaboration API
        $router->get("$prefix/collaboration/{contentId}/state", [CollaborationApiController::class, 'getState'], 'cms.api.collaboration.state');
        $router->post("$prefix/collaboration/{contentId}/update", [CollaborationApiController::class, 'applyUpdate'], 'cms.api.collaboration.update');
        $router->post("$prefix/collaboration/{contentId}/join", [CollaborationApiController::class, 'join'], 'cms.api.collaboration.join');
        $router->post("$prefix/collaboration/{contentId}/leave", [CollaborationApiController::class, 'leave'], 'cms.api.collaboration.leave');
        $router->post("$prefix/collaboration/{contentId}/awareness", [CollaborationApiController::class, 'awareness'], 'cms.api.collaboration.awareness');

        // Commerce API (conditional)
        if ($config->commerce !== null) {
            $router->get("$prefix/products", [CommerceApiController::class, 'listProducts'], 'cms.api.products.index');
            $router->get("$prefix/products/{id}", [CommerceApiController::class, 'showProduct'], 'cms.api.products.show');
            $router->get("$prefix/orders", [CommerceApiController::class, 'listOrders'], 'cms.api.orders.index');
            $router->get("$prefix/orders/{id}", [CommerceApiController::class, 'showOrder'], 'cms.api.orders.show');
        }

        // AI content assistant API (conditional)
        if ($config->ai->enabled) {
            $router->post("$prefix/ai/generate-draft", [AiAssistantApiController::class, 'generateDraft'], 'cms.api.ai.generate_draft');
            $router->post("$prefix/ai/summarize", [AiAssistantApiController::class, 'summarize'], 'cms.api.ai.summarize');
            $router->post("$prefix/ai/suggest-titles", [AiAssistantApiController::class, 'suggestTitles'], 'cms.api.ai.suggest_titles');
            $router->post("$prefix/ai/suggest-meta", [AiAssistantApiController::class, 'suggestMeta'], 'cms.api.ai.suggest_meta');
            $router->post("$prefix/ai/translate", [AiAssistantApiController::class, 'translate'], 'cms.api.ai.translate');
            $router->post("$prefix/ai/improve-readability", [AiAssistantApiController::class, 'improveReadability'], 'cms.api.ai.improve_readability');
            $router->post("$prefix/ai/generate-outline", [AiAssistantApiController::class, 'generateOutline'], 'cms.api.ai.generate_outline');
            $router->post("$prefix/ai/expand-content", [AiAssistantApiController::class, 'expandContent'], 'cms.api.ai.expand_content');
            $router->post("$prefix/ai/condense-content", [AiAssistantApiController::class, 'condenseContent'], 'cms.api.ai.condense_content');
            $router->post("$prefix/ai/adjust-tone", [AiAssistantApiController::class, 'adjustTone'], 'cms.api.ai.adjust_tone');
            $router->post("$prefix/ai/generate-faq", [AiAssistantApiController::class, 'generateFaq'], 'cms.api.ai.generate_faq');
            $router->post("$prefix/ai/generate-product-description", [AiAssistantApiController::class, 'generateProductDescription'], 'cms.api.ai.generate_product_description');
            $router->post("$prefix/ai/extract-keywords", [AiAssistantApiController::class, 'extractKeywords'], 'cms.api.ai.extract_keywords');
            $router->post("$prefix/ai/analyze-seo-score", [AiAssistantApiController::class, 'analyzeSeoScore'], 'cms.api.ai.analyze_seo_score');
            $router->post("$prefix/ai/suggest-slug", [AiAssistantApiController::class, 'suggestSlug'], 'cms.api.ai.suggest_slug');
            $router->post("$prefix/ai/generate-alt-text", [AiAssistantApiController::class, 'generateAltText'], 'cms.api.ai.generate_alt_text');
            $router->post("$prefix/ai/optimize-headings", [AiAssistantApiController::class, 'optimizeHeadings'], 'cms.api.ai.optimize_headings');
        }

        // SERP preview (pure logic, no AI dependency)
        $router->post("$prefix/ai/serp-preview", [AiAssistantApiController::class, 'generateSerpPreview'], 'cms.api.ai.serp_preview');

        // Comment API (public read, submission via CommentController)
        $router->get('/api/cms/comments', [CommentApiController::class, 'index'], 'cms.api.comments.index');

        // Newsletter API
        $router->post('/api/cms/newsletter/subscribe', [NewsletterApiController::class, 'subscribe'], 'cms.api.newsletter.subscribe');

        // Content type feeds (RSS/Atom per content type)
        $router->get('/api/cms/feed/{contentType}/rss', [NewsletterFeedController::class, 'rss'], 'cms.api.feed.rss');
        $router->get('/api/cms/feed/{contentType}/atom', [NewsletterFeedController::class, 'atom'], 'cms.api.feed.atom');

        // Documentation feedback API
        $router->post("$prefix/docs/feedback", [DocFeedbackController::class, 'submit'], 'cms.api.docs.feedback.submit');
        $router->get("$prefix/docs/{docPageId}/feedback", [DocFeedbackController::class, 'summary'], 'cms.api.docs.feedback.summary');
    }

    private function registerPublicRoutes(RouterInterface $router, CmsConfig $config, bool $pageCacheAvailable = false): void
    {
        // Customer account (front-office)
        $router->get('/account', [AccountController::class, 'dashboard'], 'cms.account.dashboard');
        $router->get('/account/profile', [AccountController::class, 'profile'], 'cms.account.profile');
        $router->post('/account/profile', [AccountController::class, 'updateProfile'], 'cms.account.profile.update');
        $router->get('/account/settings', [AccountController::class, 'settings'], 'cms.account.settings');
        $router->get('/account/section/{sectionId}', [AccountController::class, 'section'], 'cms.account.section');

        // Commerce public routes
        if ($config->commerce !== null) {
            foreach ($config->supportedLocales as $locale) {
                $router->get("/$locale/checkout", [CheckoutController::class, 'show'], "cms.checkout.show.$locale");
                $router->post("/$locale/checkout", [CheckoutController::class, 'process'], "cms.checkout.process.$locale");
                $router->get("/$locale/checkout/success", [CheckoutController::class, 'success'], "cms.checkout.success.$locale");
            }

            // Digital download (token-based, locale-independent)
            $router->get('/download/{token}', [DigitalDownloadController::class, 'download'], 'cms.download');

            // Payment webhook endpoint
            $router->post('/webhooks/cms-payment', [WebhookController::class, 'handle'], 'cms.webhook.payment');
        }

        // Public media delivery (derivatives and originals) with hotlink protection
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/media/{variant}/{hash}/{filename}.{format}',
            handler: [MediaController::class, 'serve'],
            name: 'cms.media.serve',
            middleware: [HotlinkProtectionMiddleware::class],
        ));
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/media/original/{hash}/{filename}',
            handler: [MediaController::class, 'serveOriginal'],
            name: 'cms.media.serve_original',
            middleware: [HotlinkProtectionMiddleware::class],
        ));

        // SEO public endpoints: sitemap.xml and robots.txt
        $router->get('/sitemap.xml', [SitemapController::class, 'index'], 'cms.sitemap.index');
        $router->get('/sitemap/{contentType}.xml', [SitemapController::class, 'forType'], 'cms.sitemap.type');
        $router->get('/robots.txt', [SeoController::class, 'robotsTxt'], 'cms.robots_txt');

        // Search engine verification file routes
        if ($config->seo->googleSiteVerification !== null) {
            $router->get('/google' . $config->seo->googleSiteVerification . '.html', [SeoController::class, 'googleVerification'], 'cms.seo.google_verification');
        }
        if ($config->seo->bingSiteVerification !== null) {
            $router->get('/BingSiteAuth.xml', [SeoController::class, 'bingVerification'], 'cms.seo.bing_verification');
        }

        // Form submissions (public POST endpoint)
        $router->post('/forms/submit', [FormSubmissionController::class, 'submit'], 'cms.forms.submit');

        // RSS/Atom feeds (default locale)
        $router->get('/feed/rss', [FeedController::class, 'rss'], 'cms.feed.rss');
        $router->get('/feed/atom', [FeedController::class, 'atom'], 'cms.feed.atom');

        // RSS/Atom feeds (locale-specific)
        foreach ($config->supportedLocales as $locale) {
            $router->get("/$locale/feed/rss", [FeedController::class, 'rss'], "cms.feed.rss.$locale");
            $router->get("/$locale/feed/atom", [FeedController::class, 'atom'], "cms.feed.atom.$locale");
        }

        // Newsletter public endpoints
        $router->get('/newsletter/unsubscribe/{token}', [UnsubscribeController::class, 'unsubscribe'], 'cms.newsletter.unsubscribe');
        $router->get('/newsletter/confirm/{token}', [UnsubscribeController::class, 'confirm'], 'cms.newsletter.confirm');
        $router->get('/newsletter/track/pixel/{campaignId}/{sendId}', [TrackingController::class, 'pixel'], 'cms.newsletter.track.pixel');
        $router->get('/newsletter/track/click/{campaignId}/{sendId}/{linkHash}', [TrackingController::class, 'click'], 'cms.newsletter.track.click');
        $router->post('/webhooks/newsletter-bounce', [BounceWebhookController::class, 'handle'], 'cms.newsletter.bounce');

        // Resume print-to-PDF view
        $router->get('/resume/{slug}/print', [ResumePdfController::class, 'printView'], 'cms.resume.print');

        // Public content rendering: catch-all route for locale-prefixed and default paths
        // Locale-aware routing: /{locale}/{path} or /{path} for default locale
        //
        // The page cache middleware runs AFTER CmsLocaleMiddleware (it keys on
        // the cms_locale attribute the latter sets) and is attached only when
        // the provider actually built it (application cache enabled): a bare
        // class-string here would make the pipeline instantiate it without its
        // required dependencies.
        $localeMiddleware = $pageCacheAvailable
            ? [CmsLocaleMiddleware::class, CmsPageCacheMiddleware::class]
            : [CmsLocaleMiddleware::class];

        // Register locale-prefixed routes FIRST so they take priority over the
        // default locale's catch-all /{path}. The radix tree router checks pattern
        // routes in insertion order; /fr/{path} must be registered before /{path}
        // to prevent the catch-all from swallowing locale-prefixed requests.
        foreach ($config->supportedLocales as $locale) {
            if ($locale === $config->defaultLocale && !$config->defaultLocaleInUrl) {
                continue; // Default locale routes registered below
            }

            // Locale root: /{locale}
            $router->add(new Route(
                methods: [Method::GET, Method::HEAD],
                path: "/$locale",
                handler: [ContentController::class, 'show'],
                name: "cms.content.show.$locale.root",
                middleware: $localeMiddleware,
            ));
            // Locale paths: /{locale}/{path} (allows multi-segment paths)
            $router->add(new Route(
                methods: [Method::GET, Method::HEAD],
                path: "/$locale/{path}",
                handler: [ContentController::class, 'show'],
                name: "cms.content.show.$locale",
                constraints: ['path' => '.+'],
                middleware: $localeMiddleware,
            ));
        }

        // Catch-all /{path} route — registered LAST so locale-specific routes
        // take priority. Serves two purposes:
        // 1. Default locale without prefix (defaultLocaleInUrl=false): /about → EN
        // 2. Stripped paths (defaultLocaleInUrl=true + global LocalePrefixMiddleware):
        //    /fr/a-propos is stripped to /a-propos, matched here, locale from _locale attr
        {
            // Root path for default locale
            $router->add(new Route(
                methods: [Method::GET, Method::HEAD],
                path: '/',
                handler: [ContentController::class, 'show'],
                name: 'cms.content.show.root',
                middleware: $localeMiddleware,
            ));
            // Default locale served without prefix: /{path}
            $router->add(new Route(
                methods: [Method::GET, Method::HEAD],
                path: '/{path}',
                handler: [ContentController::class, 'show'],
                name: 'cms.content.show',
                constraints: ['path' => '.+'],
                middleware: $localeMiddleware,
            ));
        }
    }

    private function registerAdminRoutes(RouterInterface $router, ContainerInterface $container): void
    {
        $prefix = '/admin/cms';

        // Static assets (path may contain subdirectories like css/admin.css)
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: "$prefix/assets/{path}",
            handler: [AssetController::class, 'serve'],
            name: 'cms.admin.assets',
            constraints: ['path' => '.+'],
        ));

        // Dashboard
        $router->get($prefix, [AdminDashboardController::class, 'index'], 'cms.admin.dashboard');

        // Bulk content operations (must precede /content/{id} to avoid route conflict)
        $router->post("$prefix/content/bulk/{action}", [BulkOperationsController::class, 'execute'], 'cms.admin.content.bulk');

        // Content CRUD
        $router->get("$prefix/content", [AdminContentController::class, 'index'], 'cms.admin.content.index');
        $router->post("$prefix/content", [AdminContentController::class, 'create'], 'cms.admin.content.create');
        $router->get("$prefix/content/{id}", [AdminContentController::class, 'show'], 'cms.admin.content.show');
        $router->put("$prefix/content/{id}", [AdminContentController::class, 'update'], 'cms.admin.content.update');
        $router->delete("$prefix/content/{id}", [AdminContentController::class, 'delete'], 'cms.admin.content.delete');

        // Content locale translations
        $router->post("$prefix/content/{id}/translations", [AdminContentController::class, 'addTranslation'], 'cms.admin.content.add_translation');

        // Content workflow actions
        $router->post("$prefix/content/{id}/publish", [AdminContentController::class, 'publish'], 'cms.admin.content.publish');
        $router->post("$prefix/content/{id}/archive", [AdminContentController::class, 'archive'], 'cms.admin.content.archive');
        $router->post("$prefix/content/{id}/schedule", [AdminContentController::class, 'schedule'], 'cms.admin.content.schedule');
        $router->post("$prefix/content/{id}/submit-review", [AdminContentController::class, 'submitReview'], 'cms.admin.content.submit_review');

        // Content locking
        $router->post("$prefix/content/{id}/lock", [AdminContentController::class, 'acquireLock'], 'cms.admin.content.lock');
        $router->delete("$prefix/content/{id}/lock", [AdminContentController::class, 'releaseLock'], 'cms.admin.content.unlock');
        $router->post("$prefix/content/{id}/break-lock", [AdminContentController::class, 'breakLock'], 'cms.admin.content.break_lock');

        // Live preview
        $router->get("$prefix/content/{id}/preview", [Http\Controller\Admin\PreviewController::class, 'show'], 'cms.admin.content.preview');
        $router->get("$prefix/content/{id}/preview/render", [Http\Controller\Admin\PreviewController::class, 'render'], 'cms.admin.content.preview.render');
        $router->post("$prefix/content/{id}/preview/render", [Http\Controller\Admin\PreviewController::class, 'render'], 'cms.admin.content.preview.render.post');

        // Revisions
        $router->get("$prefix/content/{contentId}/revisions", [RevisionController::class, 'index'], 'cms.admin.revisions.index');
        $router->get("$prefix/content/{contentId}/revisions/diff", [RevisionController::class, 'diff'], 'cms.admin.revisions.diff');
        $router->post("$prefix/content/{contentId}/revisions/{revisionId}/restore", [RevisionController::class, 'restore'], 'cms.admin.revisions.restore');

        // Editorial reviews
        $router->get("$prefix/reviews", [ReviewController::class, 'index'], 'cms.admin.reviews.index');
        $router->post("$prefix/reviews/{reviewId}/approve", [ReviewController::class, 'approve'], 'cms.admin.reviews.approve');
        $router->post("$prefix/reviews/{reviewId}/reject", [ReviewController::class, 'reject'], 'cms.admin.reviews.reject');

        // Taxonomies
        $router->get("$prefix/taxonomies", [AdminTaxonomyController::class, 'index'], 'cms.admin.taxonomies.index');
        $router->post("$prefix/taxonomies", [AdminTaxonomyController::class, 'create'], 'cms.admin.taxonomies.create');
        $router->get("$prefix/taxonomies/{slug}", [AdminTaxonomyController::class, 'show'], 'cms.admin.taxonomies.show');
        $router->put("$prefix/taxonomies/{slug}", [AdminTaxonomyController::class, 'update'], 'cms.admin.taxonomies.update');
        $router->delete("$prefix/taxonomies/{slug}", [AdminTaxonomyController::class, 'delete'], 'cms.admin.taxonomies.delete');

        // Menus
        $router->get("$prefix/menus", [AdminMenuController::class, 'index'], 'cms.admin.menus.index');
        $router->post("$prefix/menus", [AdminMenuController::class, 'create'], 'cms.admin.menus.create');
        $router->get("$prefix/menus/{location}", [AdminMenuController::class, 'show'], 'cms.admin.menus.show');
        $router->put("$prefix/menus/{location}", [AdminMenuController::class, 'update'], 'cms.admin.menus.update');
        $router->delete("$prefix/menus/{location}", [AdminMenuController::class, 'delete'], 'cms.admin.menus.delete');

        // Media
        $router->get("$prefix/media", [AdminMediaController::class, 'index'], 'cms.admin.media.index');
        $router->post("$prefix/media", [AdminMediaController::class, 'upload'], 'cms.admin.media.upload');
        $router->get("$prefix/media/{id}", [AdminMediaController::class, 'show'], 'cms.admin.media.show');
        $router->delete("$prefix/media/{id}", [AdminMediaController::class, 'delete'], 'cms.admin.media.delete');
        $router->get("$prefix/media/{id}/variants", [AdminMediaController::class, 'variants'], 'cms.admin.media.variants');

        // Comments (moderation queue + CRUD)
        $router->get("$prefix/comments", [AdminCommentController::class, 'index'], 'cms.admin.comments.index');
        $router->get("$prefix/comments/queue", [AdminCommentController::class, 'queue'], 'cms.admin.comments.queue');
        $router->get("$prefix/comments/{id}", [AdminCommentController::class, 'show'], 'cms.admin.comments.show');
        $router->post("$prefix/comments/{id}/moderate", [AdminCommentController::class, 'moderate'], 'cms.admin.comments.moderate');
        $router->post("$prefix/comments/{id}/approve", [AdminCommentController::class, 'approve'], 'cms.admin.comments.approve');
        $router->post("$prefix/comments/{id}/reject", [AdminCommentController::class, 'reject'], 'cms.admin.comments.reject');
        $router->post("$prefix/comments/{id}/spam", [AdminCommentController::class, 'markSpam'], 'cms.admin.comments.spam');
        $router->delete("$prefix/comments/{id}", [AdminCommentController::class, 'delete'], 'cms.admin.comments.delete');
        $router->post("$prefix/comments/bulk", [AdminCommentController::class, 'bulkAction'], 'cms.admin.comments.bulk');

        // Custom fields
        $router->get("$prefix/fields/{contentType}", [FieldController::class, 'index'], 'cms.admin.fields.index');
        $router->post("$prefix/fields/{contentType}", [FieldController::class, 'create'], 'cms.admin.fields.create');
        $router->put("$prefix/fields/{contentType}/{fieldId}", [FieldController::class, 'update'], 'cms.admin.fields.update');
        $router->delete("$prefix/fields/{contentType}/{fieldId}", [FieldController::class, 'delete'], 'cms.admin.fields.delete');

        // Business profile settings (must precede /settings/{group} to avoid route conflict)
        $router->get("$prefix/settings/business", [Http\Controller\Admin\BusinessProfileSettingsController::class, 'edit'], 'cms.admin.settings.business');
        $router->post("$prefix/settings/business", [Http\Controller\Admin\BusinessProfileSettingsController::class, 'update'], 'cms.admin.settings.business.update');

        // Settings
        $router->get("$prefix/settings/{group}", [SettingsController::class, 'show'], 'cms.admin.settings.show');
        $router->post("$prefix/settings/{group}", [SettingsController::class, 'update'], 'cms.admin.settings.update_post');
        $router->put("$prefix/settings/{group}", [SettingsController::class, 'update'], 'cms.admin.settings.update');

        // Themes (conditional; requires ThemeManagerInterface to be bound)
        if ($container->has(ThemeController::class)) {
            $router->get("$prefix/themes", [ThemeController::class, 'index'], 'cms.admin.themes.index');
            $router->post("$prefix/themes", [ThemeController::class, 'install'], 'cms.admin.themes.install');
            $router->post("$prefix/themes/{id}/activate", [ThemeController::class, 'activate'], 'cms.admin.themes.activate');
            $router->post("$prefix/themes/{id}/deactivate", [ThemeController::class, 'deactivate'], 'cms.admin.themes.deactivate');
            $router->post("$prefix/themes/{id}/preview", [ThemeController::class, 'preview'], 'cms.admin.themes.preview');
            $router->delete("$prefix/themes/{id}", [ThemeController::class, 'delete'], 'cms.admin.themes.delete');
        }

        // Plugins
        $router->get("$prefix/plugins", [PluginController::class, 'index'], 'cms.admin.plugins.index');
        $router->post("$prefix/plugins", [PluginController::class, 'install'], 'cms.admin.plugins.install');
        $router->post("$prefix/plugins/{id}/toggle", [PluginController::class, 'toggle'], 'cms.admin.plugins.toggle');
        $router->get("$prefix/plugins/{id}/settings", [PluginController::class, 'settings'], 'cms.admin.plugins.settings');
        $router->put("$prefix/plugins/{id}/settings", [PluginController::class, 'updateSettings'], 'cms.admin.plugins.update_settings');
        $router->delete("$prefix/plugins/{id}", [PluginController::class, 'delete'], 'cms.admin.plugins.delete');

        // Users
        $router->get("$prefix/users", [UserController::class, 'index'], 'cms.admin.users.index');
        $router->get("$prefix/users/{id}", [UserController::class, 'show'], 'cms.admin.users.show');
        $router->put("$prefix/users/{id}", [UserController::class, 'update'], 'cms.admin.users.update');
        $router->post("$prefix/users/{id}/reset-2fa", [UserController::class, 'resetTwoFactor'], 'cms.admin.users.reset_2fa');

        // GDPR Tools
        $router->post("$prefix/tools/gdpr/export", [ToolsController::class, 'exportUserData'], 'cms.admin.tools.gdpr_export');
        $router->post("$prefix/tools/gdpr/erase", [ToolsController::class, 'eraseUserData'], 'cms.admin.tools.gdpr_erase');

        // Search analytics
        $router->get("$prefix/search-analytics", [SearchAnalyticsController::class, 'index'], 'cms.admin.search_analytics.index');

        // SEO: Redirects
        $router->get("$prefix/seo/redirects", [AdminRedirectController::class, 'index'], 'cms.admin.redirects.index');
        $router->post("$prefix/seo/redirects", [AdminRedirectController::class, 'create'], 'cms.admin.redirects.create');
        $router->delete("$prefix/seo/redirects/{id}", [AdminRedirectController::class, 'delete'], 'cms.admin.redirects.delete');
        $router->post("$prefix/seo/redirects/bulk-import", [AdminRedirectController::class, 'bulkImport'], 'cms.admin.redirects.bulk_import');
        $router->get("$prefix/seo/redirects/export", [AdminRedirectController::class, 'export'], 'cms.admin.redirects.export');

        // SEO: Link Health
        $router->get("$prefix/seo/link-health", [LinkHealthController::class, 'index'], 'cms.admin.link_health.index');
        $router->post("$prefix/seo/link-health/check", [LinkHealthController::class, 'runCheck'], 'cms.admin.link_health.check');

        // SEO: Sitemap
        $router->get("$prefix/seo/sitemap", [AdminSitemapController::class, 'preview'], 'cms.admin.sitemap.preview');
        $router->post("$prefix/seo/sitemap/regenerate", [AdminSitemapController::class, 'regenerate'], 'cms.admin.sitemap.regenerate');

        // SEO: Robots.txt management
        $router->get("$prefix/seo/robots", [RobotsController::class, 'show'], 'cms.admin.robots.show');
        $router->put("$prefix/seo/robots", [RobotsController::class, 'update'], 'cms.admin.robots.update');

        // Two-factor authentication
        $router->get("$prefix/2fa", [TwoFactorController::class, 'status'], 'cms.admin.2fa.status');
        $router->get("$prefix/2fa/enroll", [TwoFactorController::class, 'enroll'], 'cms.admin.2fa.enroll_form');
        $router->post("$prefix/2fa/enroll", [TwoFactorController::class, 'enroll'], 'cms.admin.2fa.enroll');
        $router->post("$prefix/2fa/confirm", [TwoFactorController::class, 'confirm'], 'cms.admin.2fa.confirm');
        $router->post("$prefix/2fa/verify", [TwoFactorController::class, 'verify'], 'cms.admin.2fa.verify');
        $router->post("$prefix/2fa/disable", [TwoFactorController::class, 'disable'], 'cms.admin.2fa.disable');
        $router->post("$prefix/2fa/recovery-codes", [TwoFactorController::class, 'regenerateRecoveryCodes'], 'cms.admin.2fa.recovery_codes');

        // Products (commerce)
        $router->get("$prefix/products", [ProductController::class, 'index'], 'cms.admin.products.index');
        $router->get("$prefix/products/create", [ProductController::class, 'create'], 'cms.admin.products.create');
        $router->post("$prefix/products", [ProductController::class, 'store'], 'cms.admin.products.store');
        $router->get("$prefix/products/{id}/edit", [ProductController::class, 'edit'], 'cms.admin.products.edit');
        $router->put("$prefix/products/{id}", [ProductController::class, 'update'], 'cms.admin.products.update');
        $router->delete("$prefix/products/{id}", [ProductController::class, 'delete'], 'cms.admin.products.delete');

        // Orders (commerce)
        $router->get("$prefix/orders", [OrderController::class, 'index'], 'cms.admin.orders.index');
        $router->get("$prefix/orders/{id}", [OrderController::class, 'show'], 'cms.admin.orders.show');
        $router->post("$prefix/orders/{id}/refund", [OrderController::class, 'refund'], 'cms.admin.orders.refund');
        $router->get("$prefix/orders/export", [OrderController::class, 'export'], 'cms.admin.orders.export');

        // Promotions (commerce)
        $router->get("$prefix/promotions", [PromotionController::class, 'index'], 'cms.admin.promotions.index');
        $router->get("$prefix/promotions/create", [PromotionController::class, 'create'], 'cms.admin.promotions.create');
        $router->post("$prefix/promotions", [PromotionController::class, 'store'], 'cms.admin.promotions.store');
        $router->get("$prefix/promotions/{id}/edit", [PromotionController::class, 'edit'], 'cms.admin.promotions.edit');
        $router->put("$prefix/promotions/{id}", [PromotionController::class, 'update'], 'cms.admin.promotions.update');
        $router->delete("$prefix/promotions/{id}", [PromotionController::class, 'delete'], 'cms.admin.promotions.delete');

        // Digital assets (commerce): nested under products since assets belong to a product
        $router->get("$prefix/products/{productId}/digital-assets", [DigitalAssetController::class, 'index'], 'cms.admin.digital_assets.index');
        $router->post("$prefix/products/{productId}/digital-assets", [DigitalAssetController::class, 'upload'], 'cms.admin.digital_assets.upload');
        $router->delete("$prefix/products/{productId}/digital-assets/{assetId}", [DigitalAssetController::class, 'delete'], 'cms.admin.digital_assets.delete');

        // Invoices (commerce)
        $router->get("$prefix/invoices/{id}", [InvoiceController::class, 'show'], 'cms.admin.invoices.show');
        $router->get("$prefix/invoices/{id}/download", [InvoiceController::class, 'download'], 'cms.admin.invoices.download');

        // Live CSS editor (conditional; requires ThemeManagerInterface to be bound)
        if ($container->has(LiveCssController::class)) {
            $router->get("$prefix/live-css", [LiveCssController::class, 'editor'], 'cms.admin.livecss.editor');
            $router->post("$prefix/live-css", [LiveCssController::class, 'save'], 'cms.admin.livecss.save');
            $router->post("$prefix/live-css/{id}/rollback", [LiveCssController::class, 'rollback'], 'cms.admin.livecss.rollback');
            $router->get("$prefix/live-css/history", [LiveCssController::class, 'history'], 'cms.admin.livecss.history');
        }

        // Export
        $router->get("$prefix/export", [ExportController::class, 'form'], 'cms.admin.export.form');
        $router->get("$prefix/export/selective", [ExportController::class, 'selectiveForm'], 'cms.admin.export.selective');
        $router->post("$prefix/export/download", [ExportController::class, 'download'], 'cms.admin.export.download');
        $router->post("$prefix/export/zip", [ExportController::class, 'zipDownload'], 'cms.admin.export.zip');
        $router->get("$prefix/export/markdown", [ExportController::class, 'markdownExport'], 'cms.admin.export.markdown');
        $router->get("$prefix/export/csv", [ExportController::class, 'csvExport'], 'cms.admin.export.csv');
        // Export routes under tools/ prefix (used by admin UI forms)
        $router->post("$prefix/tools/export/download", [ExportController::class, 'download'], 'cms.admin.tools.export.download');
        $router->post("$prefix/tools/export/zip", [ExportController::class, 'zipDownload'], 'cms.admin.tools.export.zip');

        // Import
        $router->get("$prefix/import", [ImportController::class, 'form'], 'cms.admin.import.form');
        $router->post("$prefix/import/dry-run", [ImportController::class, 'dryRun'], 'cms.admin.import.dry_run');
        $router->post("$prefix/import/execute", [ImportController::class, 'execute'], 'cms.admin.import.execute');
        $router->post("$prefix/import/analyze", [ImportController::class, 'analyzeUpload'], 'cms.admin.import.analyze');
        $router->post("$prefix/import/execute-with-options", [ImportController::class, 'executeWithOptions'], 'cms.admin.import.execute_with_options');
        $router->post("$prefix/import/markdown", [ImportController::class, 'markdownImport'], 'cms.admin.import.markdown');
        $router->post("$prefix/import/csv", [ImportController::class, 'csvImport'], 'cms.admin.import.csv');
        // Import routes under tools/ prefix (used by admin UI forms)
        $router->post("$prefix/tools/import/dry-run", [ImportController::class, 'dryRun'], 'cms.admin.tools.import.dry_run');
        $router->post("$prefix/tools/import/execute", [ImportController::class, 'execute'], 'cms.admin.tools.import.execute');
        $router->post("$prefix/tools/import/analyze", [ImportController::class, 'analyzeUpload'], 'cms.admin.tools.import.analyze');
        $router->post("$prefix/tools/import/execute-with-options", [ImportController::class, 'executeWithOptions'], 'cms.admin.tools.import.execute_with_options');

        // Site definition import
        $router->get("$prefix/site-import", [SiteDefinitionController::class, 'form'], 'cms.admin.site_import.form');
        $router->post("$prefix/site-import/dry-run", [SiteDefinitionController::class, 'dryRun'], 'cms.admin.site_import.dry_run');
        $router->post("$prefix/site-import/execute", [SiteDefinitionController::class, 'execute'], 'cms.admin.site_import.execute');
        // Site import routes under tools/ prefix (used by admin UI forms)
        $router->post("$prefix/tools/site-import/dry-run", [SiteDefinitionController::class, 'dryRun'], 'cms.admin.tools.site_import.dry_run');
        $router->post("$prefix/tools/site-import/execute", [SiteDefinitionController::class, 'execute'], 'cms.admin.tools.site_import.execute');

        // A/B Testing (experiments)
        $router->get("$prefix/experiments", [ExperimentController::class, 'index'], 'cms.admin.experiments.index');
        $router->post("$prefix/experiments", [ExperimentController::class, 'create'], 'cms.admin.experiments.create');
        $router->get("$prefix/experiments/{id}", [ExperimentController::class, 'show'], 'cms.admin.experiments.show');
        $router->post("$prefix/experiments/{id}/start", [ExperimentController::class, 'start'], 'cms.admin.experiments.start');
        $router->post("$prefix/experiments/{id}/stop", [ExperimentController::class, 'stop'], 'cms.admin.experiments.stop');
        $router->get("$prefix/experiments/{id}/results", [ExperimentController::class, 'results'], 'cms.admin.experiments.results');

        // Backups
        $router->get("$prefix/backups", [BackupController::class, 'index'], 'cms.admin.backups.index');
        $router->post("$prefix/backups", [BackupController::class, 'create'], 'cms.admin.backups.create');
        $router->post("$prefix/backups/{id}/restore", [BackupController::class, 'restore'], 'cms.admin.backups.restore');
        $router->delete("$prefix/backups/{id}", [BackupController::class, 'delete'], 'cms.admin.backups.delete');

        // Newsletter management
        $router->get("$prefix/newsletter/subscribers", [AdminNewsletterController::class, 'subscribers'], 'cms.admin.newsletter.subscribers');
        $router->get("$prefix/newsletter/subscribers/{id}", [AdminNewsletterController::class, 'subscriberDetail'], 'cms.admin.newsletter.subscriber_detail');
        $router->delete("$prefix/newsletter/subscribers/{id}", [AdminNewsletterController::class, 'deleteSubscriber'], 'cms.admin.newsletter.subscriber_delete');
        $router->get("$prefix/newsletter/campaigns", [AdminNewsletterController::class, 'campaigns'], 'cms.admin.newsletter.campaigns');
        $router->get("$prefix/newsletter/campaigns/create", [AdminNewsletterController::class, 'campaignForm'], 'cms.admin.newsletter.campaign_create');
        $router->post("$prefix/newsletter/campaigns", [AdminNewsletterController::class, 'createCampaign'], 'cms.admin.newsletter.campaign_store');
        $router->get("$prefix/newsletter/campaigns/{id}/edit", [AdminNewsletterController::class, 'campaignForm'], 'cms.admin.newsletter.campaign_edit');
        $router->put("$prefix/newsletter/campaigns/{id}", [AdminNewsletterController::class, 'updateCampaign'], 'cms.admin.newsletter.campaign_update');
        $router->delete("$prefix/newsletter/campaigns/{id}", [AdminNewsletterController::class, 'deleteCampaign'], 'cms.admin.newsletter.campaign_delete');
        $router->post("$prefix/newsletter/campaigns/{id}/send", [AdminNewsletterController::class, 'sendCampaign'], 'cms.admin.newsletter.campaign_send');
        $router->get("$prefix/newsletter/campaigns/{id}/analytics", [AdminNewsletterController::class, 'campaignAnalytics'], 'cms.admin.newsletter.campaign_analytics');

        // Rate limiting dashboard
        $router->get("$prefix/rate-limits", [RateLimitDashboardController::class, 'dashboard'], 'cms.admin.rate_limits.dashboard');
        $router->post("$prefix/rate-limits", [RateLimitDashboardController::class, 'updateLimit'], 'cms.admin.rate_limits.update');

        // Documentation versions
        $router->get("$prefix/docs/versions", [DocVersionController::class, 'index'], 'cms.admin.docs.versions.index');
        $router->put("$prefix/docs/versions/default", [DocVersionController::class, 'setDefault'], 'cms.admin.docs.versions.set_default');

        // Form submissions
        $router->get("$prefix/forms", [AdminFormSubmissionController::class, 'index'], 'cms.admin.forms.index');
        $router->get("$prefix/forms/export", [AdminFormSubmissionController::class, 'export'], 'cms.admin.forms.export');
        $router->get("$prefix/forms/{id}", [AdminFormSubmissionController::class, 'show'], 'cms.admin.forms.show');
        $router->post("$prefix/forms/{id}/read", [AdminFormSubmissionController::class, 'markAsRead'], 'cms.admin.forms.read');
        $router->post("$prefix/forms/{id}/spam", [AdminFormSubmissionController::class, 'markAsSpam'], 'cms.admin.forms.spam');
        $router->post("$prefix/forms/bulk-delete", [AdminFormSubmissionController::class, 'bulkDelete'], 'cms.admin.forms.bulk_delete');
    }

    private function registerImportExportProvider(ContainerInterface $container): void
    {
        if (!$container->has(ImportExportRegistry::class)) {
            return;
        }

        if (!$container->has(ImportExportServiceInterface::class)) {
            return;
        }

        /** @var ImportExportRegistry $registry */
        $registry = $container->get(ImportExportRegistry::class);

        /** @var ImportExportServiceInterface $importExportService */
        $importExportService = $container->get(ImportExportServiceInterface::class);

        $registry->register(new CmsImportExportProvider($importExportService));
    }

    private function registerSchedulerJobs(ContainerInterface $container): void
    {
        if (!$container->has(JobRegistry::class)) {
            return;
        }

        /** @var JobRegistry $jobRegistry */
        $jobRegistry = $container->get(JobRegistry::class);

        // Scheduled publishing: every minute
        if ($container->has(ContentRepositoryInterface::class)) {
            /** @var ContentRepositoryInterface $contentRepository */
            $contentRepository = $container->get(ContentRepositoryInterface::class);
            $jobRegistry->register(new ScheduledPublishingJob($contentRepository));
        }

        // Link health check: daily at 3 AM
        if ($container->has(LinkHealthServiceInterface::class)) {
            /** @var LinkHealthServiceInterface $linkHealthService */
            $linkHealthService = $container->get(LinkHealthServiceInterface::class);
            $jobRegistry->register(new LinkHealthCheckJob($linkHealthService));
        }

        // Search analytics summary: daily at 4 AM
        if ($container->has(SearchAnalyticsRepositoryInterface::class)) {
            /** @var SearchAnalyticsRepositoryInterface $analyticsRepo */
            $analyticsRepo = $container->get(SearchAnalyticsRepositoryInterface::class);
            $jobRegistry->register(new SearchAnalyticsCleanupJob($analyticsRepo));
        }

        // Expired session and lock cleanup: every 30 minutes
        if (
            $container->has(PreviewSessionRepositoryInterface::class)
            && $container->has(ContentLockServiceInterface::class)
        ) {
            /** @var PreviewSessionRepositoryInterface $previewRepo */
            $previewRepo = $container->get(PreviewSessionRepositoryInterface::class);

            /** @var ContentLockServiceInterface $lockService */
            $lockService = $container->get(ContentLockServiceInterface::class);

            $jobRegistry->register(new ExpiredSessionCleanupJob($previewRepo, $lockService));
        }

        // Backup retention: weekly on Sundays at 2 AM
        if ($container->has(BackupServiceInterface::class)) {
            /** @var BackupServiceInterface $backupService */
            $backupService = $container->get(BackupServiceInterface::class);
            $jobRegistry->register(new BackupRetentionJob($backupService));
        }

        // Webhook retry cleanup: daily at 2 AM, purge events older than 30 days
        if ($container->has(ConnectionInterface::class)) {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);
            $jobRegistry->register(new WebhookRetryCleanupJob($connection));
        }
    }

    private function registerNotificationListeners(ContainerInterface $container): void
    {
        if (
            !$container->has(CmsNotificationDispatcher::class)
            || !$container->has(ListenerProviderInterface::class)
        ) {
            return;
        }

        /** @var CmsNotificationDispatcher $notificationDispatcher */
        $notificationDispatcher = $container->get(CmsNotificationDispatcher::class);

        /** @var ListenerProviderInterface $listenerProvider */
        $listenerProvider = $container->get(ListenerProviderInterface::class);

        $listenerProvider->addListener(
            ContentPublished::class,
            [$notificationDispatcher, 'onContentPublished'],
            moduleId: 'pulsar/cms',
        );

        $listenerProvider->addListener(
            ReviewRequested::class,
            [$notificationDispatcher, 'onReviewRequested'],
            moduleId: 'pulsar/cms',
        );

        $listenerProvider->addListener(
            CommentReceived::class,
            [$notificationDispatcher, 'onCommentReceived'],
            moduleId: 'pulsar/cms',
        );
    }
}
