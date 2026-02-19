<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Cms\Comments\CommentBodyPolicy;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Comments\CommentService;
use Pulsar\Extension\Cms\Comments\CommentServiceInterface;
use Pulsar\Extension\Cms\Commerce\CheckoutServiceInterface;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\CouponRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\DigitalAssetRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceRendererInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderExportServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PaymentGateway;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionServiceInterface;
use Pulsar\Extension\Cms\Commerce\TaxCalculatorInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Config\CmsPermissions;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\EventStore\ContentEventStoreInterface;
use Pulsar\Extension\Cms\EventStore\ContentSnapshotServiceInterface;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeRegistryInterface;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Commerce\CheckoutService;
use Pulsar\Extension\Cms\Internal\Commerce\DigitalDeliveryService;
use Pulsar\Extension\Cms\Internal\Commerce\HtmlInvoiceRenderer;
use Pulsar\Extension\Cms\Internal\Commerce\InvoiceService;
use Pulsar\Extension\Cms\Internal\Commerce\OrderService;
use Pulsar\Extension\Cms\Internal\Commerce\PromotionEngine;
use Pulsar\Extension\Cms\Internal\Commerce\TaxCalculator;
use Pulsar\Extension\Cms\Internal\Commerce\WebhookHandler;
use Pulsar\Extension\Cms\Internal\LiveCss\CspHashComputer;
use Pulsar\Extension\Cms\Internal\LiveCss\CssValidator;
use Pulsar\Extension\Cms\Internal\LiveCss\LiveCssService;
use Pulsar\Extension\Cms\Internal\LiveCss\ThemeTokenResolver;
use Pulsar\Extension\Cms\Internal\Persistence\DbCmsPluginRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbCmsUserRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbCommentRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentBlockRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentEventRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentLockRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentRevisionRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentSnapshotRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentTranslationRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbCouponRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbCssOverrideRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbDigitalAssetRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbEditorialReviewRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbFieldRegistryRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbInvoiceRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbLinkHealthRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbMediaRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbMenuRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbOrderItemRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbOrderRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbProductRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbPromotionRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbRedirectRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbSearchAnalyticsRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbSettingsRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbTaxonomyRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbThemeRepository;
use Pulsar\Extension\Cms\Internal\Plugins\CmsPluginManager;
use Pulsar\Extension\Cms\Internal\Plugins\HookExecutionEngine;
use Pulsar\Extension\Cms\Internal\Plugins\PluginManifestValidator;
use Pulsar\Extension\Cms\Internal\Plugins\PluginProvenanceVerifier;
use Pulsar\Extension\Cms\Internal\Search\SearchService;
use Pulsar\Extension\Cms\Internal\Security\ClientFingerprintResolver;
use Pulsar\Extension\Cms\Internal\Security\CmsKeyManager;
use Pulsar\Extension\Cms\Internal\Security\QrCodeEncoder;
use Pulsar\Extension\Cms\Internal\Security\SafeHttpClient;
use Pulsar\Extension\Cms\Internal\Seo\ArticleStructuredDataGenerator;
use Pulsar\Extension\Cms\Internal\Seo\FeedGenerator;
use Pulsar\Extension\Cms\Internal\Seo\LinkHealthChecker;
use Pulsar\Extension\Cms\Internal\Seo\RedirectManager;
use Pulsar\Extension\Cms\Internal\Seo\RobotsTxtGenerator;
use Pulsar\Extension\Cms\Internal\Seo\SeoService;
use Pulsar\Extension\Cms\Internal\Seo\SitemapGenerator;
use Pulsar\Extension\Cms\Internal\Seo\WebPageStructuredDataGenerator;
use Pulsar\Extension\Cms\Internal\Themes\SafeArchiveExtractor;
use Pulsar\Extension\Cms\Internal\Themes\ThemeAssetResolver;
use Pulsar\Extension\Cms\Internal\Themes\ThemeManager;
use Pulsar\Extension\Cms\Internal\Themes\ThemeManifestValidator;
use Pulsar\Extension\Cms\Internal\Themes\ThemeProvenanceVerifier;
use Pulsar\Extension\Cms\Internal\Tools\BackupService;
use Pulsar\Extension\Cms\Internal\Tools\ExportBundleGenerator;
use Pulsar\Extension\Cms\Internal\Tools\ImportExportService;
use Pulsar\Extension\Cms\Internal\Tools\ImportParser;
use Pulsar\Extension\Cms\Internal\Tools\OrderExportService;
use Pulsar\Extension\Cms\Internal\Tools\SiteDefinitionParser;
use Pulsar\Extension\Cms\Internal\Tools\ToolsService;
use Pulsar\Extension\Cms\LiveCss\CspHashComputerInterface;
use Pulsar\Extension\Cms\LiveCss\CssOverrideRepositoryInterface;
use Pulsar\Extension\Cms\LiveCss\CssValidatorInterface;
use Pulsar\Extension\Cms\LiveCss\LiveCssServiceInterface;
use Pulsar\Extension\Cms\LiveCss\ThemeTokenResolverInterface;
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
use Pulsar\Extension\Cms\Plugins\CmsPluginManagerInterface;
use Pulsar\Extension\Cms\Plugins\CmsPluginRepositoryInterface;
use Pulsar\Extension\Cms\Plugins\HookRegistry;
use Pulsar\Extension\Cms\Plugins\PluginManifestValidatorInterface;
use Pulsar\Extension\Cms\Plugins\PluginProvenanceVerifierInterface;
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
use Pulsar\Extension\Cms\Taxonomy\TaxonomyServiceInterface;
use Pulsar\Extension\Cms\Themes\ThemeArchiveExtractorInterface;
use Pulsar\Extension\Cms\Themes\ThemeAssetResolverInterface;
use Pulsar\Extension\Cms\Themes\ThemeManagerInterface;
use Pulsar\Extension\Cms\Themes\ThemeManifestValidatorInterface;
use Pulsar\Extension\Cms\Themes\ThemeProvenanceVerifierInterface;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;
use Pulsar\Extension\Cms\Tools\BackupServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ToolsServiceInterface;
use Pulsar\Extension\Cms\Users\CmsUserRepositoryInterface;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\Security\Crypto\MasterKey;

/**
 * Registers all CMS services, repositories, and bindings.
 */
#[Internal(reason: 'CMS service wiring — use interfaces for public API')]
final class CmsServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $this->bindRepositories($container);
        $this->bindServices($container);
        $this->registerPermissions($container);
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            ContentRepositoryInterface::class,
            ContentTranslationRepositoryInterface::class,
            ContentRevisionRepositoryInterface::class,
            ContentBlockRepositoryInterface::class,
            RedirectRepositoryInterface::class,
            TaxonomyRepositoryInterface::class,
            MenuRepositoryInterface::class,
            FieldRegistryRepositoryInterface::class,
            ContentEventStoreInterface::class,
            ContentSnapshotServiceInterface::class,
            SettingsServiceInterface::class,
            TaxonomyServiceInterface::class,
            ContentLockServiceInterface::class,
            EditorialWorkflowServiceInterface::class,
            BreadcrumbGeneratorInterface::class,
            ContentTypeRegistryInterface::class,
            MediaRepositoryInterface::class,
            MediaServiceInterface::class,
            MediaDiskInterface::class,
            ImageProcessorInterface::class,
            CommentRepositoryInterface::class,
            CommentServiceInterface::class,
            SearchServiceInterface::class,
            SearchAnalyticsRepositoryInterface::class,
            // SEO
            SeoServiceInterface::class,
            SitemapGeneratorInterface::class,
            RobotsTxtGeneratorInterface::class,
            FeedGeneratorInterface::class,
            RedirectManagerInterface::class,
            LinkHealthServiceInterface::class,
            LinkHealthRepositoryInterface::class,
            // Themes
            ThemeRepositoryInterface::class,
            ThemeManagerInterface::class,
            ThemeAssetResolverInterface::class,
            ThemeManifestValidatorInterface::class,
            ThemeProvenanceVerifierInterface::class,
            ThemeArchiveExtractorInterface::class,
            // Plugins
            CmsPluginRepositoryInterface::class,
            CmsPluginManagerInterface::class,
            PluginManifestValidatorInterface::class,
            PluginProvenanceVerifierInterface::class,
            HookRegistry::class,
            // Users
            CmsUserRepositoryInterface::class,
            // Tools
            ToolsServiceInterface::class,
            ImportExportServiceInterface::class,
            BackupServiceInterface::class,
            // Live CSS
            CssOverrideRepositoryInterface::class,
            CssValidatorInterface::class,
            CspHashComputerInterface::class,
            ThemeTokenResolverInterface::class,
            LiveCssServiceInterface::class,
            // Commerce (conditional)
            ProductRepositoryInterface::class,
            OrderRepositoryInterface::class,
            OrderItemRepositoryInterface::class,
            PromotionRepositoryInterface::class,
            DigitalAssetRepositoryInterface::class,
            InvoiceRepositoryInterface::class,
            CouponRepositoryInterface::class,
            CheckoutServiceInterface::class,
            InvoiceServiceInterface::class,
            TaxCalculatorInterface::class,
            DigitalDeliveryServiceInterface::class,
            PromotionServiceInterface::class,
            OrderExportServiceInterface::class,
            // Security
            SafeHttpClient::class,
            ClientFingerprintResolver::class,
            CmsKeyManager::class,
            QrCodeEncoder::class,
        ];
    }

    private function bindRepositories(ContainerInterface $container): void
    {
        if (!$container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var ConnectionInterface $connection */
        $connection = $container->get(ConnectionInterface::class);

        $container->instance(
            ContentRepositoryInterface::class,
            new DbContentRepository($connection),
        );

        $container->instance(
            ContentTranslationRepositoryInterface::class,
            new DbContentTranslationRepository($connection),
        );

        $container->instance(
            ContentRevisionRepositoryInterface::class,
            new DbContentRevisionRepository($connection),
        );

        $container->instance(
            ContentBlockRepositoryInterface::class,
            new DbContentBlockRepository($connection),
        );

        $container->instance(
            RedirectRepositoryInterface::class,
            new DbRedirectRepository($connection),
        );

        $container->instance(
            TaxonomyRepositoryInterface::class,
            new DbTaxonomyRepository($connection),
        );

        $container->instance(
            MenuRepositoryInterface::class,
            new DbMenuRepository($connection),
        );

        $container->instance(
            FieldRegistryRepositoryInterface::class,
            new DbFieldRegistryRepository($connection),
        );

        $container->instance(
            ContentEventStoreInterface::class,
            new DbContentEventRepository($connection),
        );

        $container->instance(
            ContentSnapshotServiceInterface::class,
            new DbContentSnapshotRepository($connection),
        );

        $container->instance(
            DbSettingsRepository::class,
            new DbSettingsRepository($connection),
        );

        $container->instance(
            DbEditorialReviewRepository::class,
            new DbEditorialReviewRepository($connection),
        );

        $container->instance(
            DbContentLockRepository::class,
            new DbContentLockRepository($connection),
        );

        $container->instance(
            MediaRepositoryInterface::class,
            new DbMediaRepository($connection),
        );

        $container->instance(
            CommentRepositoryInterface::class,
            new DbCommentRepository($connection),
        );

        $analyticsRepo = new DbSearchAnalyticsRepository($connection);
        $container->instance(SearchAnalyticsRepositoryInterface::class, $analyticsRepo);
        $container->instance(DbSearchAnalyticsRepository::class, $analyticsRepo);

        $container->instance(
            LinkHealthRepositoryInterface::class,
            new DbLinkHealthRepository($connection),
        );

        $container->instance(
            ThemeRepositoryInterface::class,
            new DbThemeRepository($connection),
        );

        $container->instance(
            CmsPluginRepositoryInterface::class,
            new DbCmsPluginRepository($connection),
        );

        $container->instance(
            CmsUserRepositoryInterface::class,
            new DbCmsUserRepository($connection),
        );

        // Live CSS repository
        $container->instance(
            CssOverrideRepositoryInterface::class,
            new DbCssOverrideRepository($connection),
        );

        // Commerce repositories
        $container->instance(
            ProductRepositoryInterface::class,
            new DbProductRepository($connection),
        );

        $container->instance(
            OrderRepositoryInterface::class,
            new DbOrderRepository($connection),
        );

        $container->instance(
            OrderItemRepositoryInterface::class,
            new DbOrderItemRepository($connection),
        );

        $container->instance(
            PromotionRepositoryInterface::class,
            new DbPromotionRepository($connection),
        );

        $container->instance(
            DigitalAssetRepositoryInterface::class,
            new DbDigitalAssetRepository($connection),
        );

        $container->instance(
            InvoiceRepositoryInterface::class,
            new DbInvoiceRepository($connection),
        );

        $container->instance(
            CouponRepositoryInterface::class,
            new DbCouponRepository($connection),
        );
    }

    private function bindServices(ContainerInterface $container): void
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
                $config->media,
                $mediaRepository,
                $disk,
                $imageProcessor,
                $fileValidator,
                $filenameSanitizer,
                $svgSanitizer,
                $pdfValidator,
                $logger,
            ),
        );

        // Comment stack
        /** @var CommentRepositoryInterface $commentRepository */
        $commentRepository = $container->get(CommentRepositoryInterface::class);

        /** @var ContentRepositoryInterface $contentRepository */
        $contentRepository = $container->get(ContentRepositoryInterface::class);

        /** @var SafeHtmlPolicy $safeHtmlPolicy */
        $safeHtmlPolicy = $container->has(SafeHtmlPolicy::class)
            ? $container->get(SafeHtmlPolicy::class)
            : new SafeHtmlPolicy();

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
            new SearchService($connection, $analyticsRepository, $tenantId),
        );

        /** @var ContentTranslationRepositoryInterface $translationRepository */
        $translationRepository = $container->get(ContentTranslationRepositoryInterface::class);

        // SEO stack
        $container->instance(
            SeoServiceInterface::class,
            new SeoService(
                $config,
                $translationRepository,
                [new ArticleStructuredDataGenerator(), new WebPageStructuredDataGenerator()],
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

        // Theme stack
        $themesConfig = $config->themes;

        $manifestValidator = new ThemeManifestValidator();
        $container->instance(ThemeManifestValidatorInterface::class, $manifestValidator);

        $provenanceVerifier = new ThemeProvenanceVerifier($themesConfig, $logger);
        $container->instance(ThemeProvenanceVerifierInterface::class, $provenanceVerifier);

        $archiveExtractor = new SafeArchiveExtractor($themesConfig, $logger);
        $container->instance(ThemeArchiveExtractorInterface::class, $archiveExtractor);

        /** @var ThemeRepositoryInterface $themeRepository */
        $themeRepository = $container->get(ThemeRepositoryInterface::class);

        $container->instance(
            ThemeAssetResolverInterface::class,
            new ThemeAssetResolver($themeRepository, $themesConfig, $logger),
        );

        if ($eventDispatcher !== null) {
            $container->instance(
                ThemeManagerInterface::class,
                new ThemeManager(
                    $themeRepository,
                    $manifestValidator,
                    $provenanceVerifier,
                    $archiveExtractor,
                    $themesConfig,
                    $eventDispatcher,
                    $auditLogger,
                    $logger,
                ),
            );
        }

        // Plugin stack
        $securityConfig = $config->security;

        $pluginManifestValidator = new PluginManifestValidator();
        $container->instance(PluginManifestValidatorInterface::class, $pluginManifestValidator);

        $pluginProvenanceVerifier = new PluginProvenanceVerifier($securityConfig, $logger);
        $container->instance(PluginProvenanceVerifierInterface::class, $pluginProvenanceVerifier);

        $hookRegistry = new HookRegistry();
        $container->instance(HookRegistry::class, $hookRegistry);

        $hookEngine = new HookExecutionEngine($hookRegistry, $auditLogger, $logger);
        $container->instance(HookExecutionEngine::class, $hookEngine);

        if ($eventDispatcher !== null) {
            /** @var CmsPluginRepositoryInterface $pluginRepository */
            $pluginRepository = $container->get(CmsPluginRepositoryInterface::class);

            $container->instance(
                CmsPluginManagerInterface::class,
                new CmsPluginManager(
                    $pluginRepository,
                    $pluginManifestValidator,
                    $pluginProvenanceVerifier,
                    $archiveExtractor,
                    $securityConfig,
                    $container,
                    $hookRegistry,
                    $hookEngine,
                    $eventDispatcher,
                    $auditLogger,
                    $logger,
                ),
            );
        }

        // Tools stack (GDPR)
        $container->instance(
            ToolsServiceInterface::class,
            new ToolsService($connection, $auditLogger),
        );

        // Security stack
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

        // Live CSS stack
        $this->bindLiveCssServices($container, $themeRepository, $auditLogger);

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

        // Commerce stack (conditional on config)
        if ($config->commerce !== null) {
            $this->bindCommerceServices($container, $connection, $config->commerce, $eventDispatcher, $auditLogger);
        }
    }

    private function bindLiveCssServices(
        ContainerInterface $container,
        ThemeRepositoryInterface $themeRepository,
        ?AuditLoggerInterface $auditLogger,
    ): void {
        $cssValidator = new CssValidator();
        $container->instance(CssValidatorInterface::class, $cssValidator);

        $cspHashComputer = new CspHashComputer();
        $container->instance(CspHashComputerInterface::class, $cspHashComputer);

        $themeTokenResolver = new ThemeTokenResolver($themeRepository);
        $container->instance(ThemeTokenResolverInterface::class, $themeTokenResolver);

        /** @var CssOverrideRepositoryInterface $cssOverrideRepository */
        $cssOverrideRepository = $container->get(CssOverrideRepositoryInterface::class);

        $container->instance(
            LiveCssServiceInterface::class,
            new LiveCssService($cssOverrideRepository, $cssValidator, $cspHashComputer, $auditLogger),
        );
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
            $taxonomyService = $container->has(TaxonomyServiceInterface::class)
                ? $container->get(TaxonomyServiceInterface::class)
                : null;

            /** @var MediaServiceInterface $mediaService */
            $mediaService = $container->get(MediaServiceInterface::class);

            /** @var SitemapGeneratorInterface $sitemapGenerator */
            $sitemapGenerator = $container->get(SitemapGeneratorInterface::class);

            /** @var SafeHttpClient $httpClient */
            $httpClient = $container->get(SafeHttpClient::class);

            if ($taxonomyService !== null) {
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
        }

        // Backup service
        $container->instance(
            BackupServiceInterface::class,
            new BackupService($connection, $disk, $auditLogger),
        );
    }

    private function bindCommerceServices(
        ContainerInterface $container,
        ConnectionInterface $connection,
        CommerceConfig $commerceConfig,
        ?EventDispatcherInterface $eventDispatcher,
        ?AuditLoggerInterface $auditLogger,
    ): void {
        // Tax calculator
        $taxCalculator = new TaxCalculator($commerceConfig);
        $container->instance(TaxCalculatorInterface::class, $taxCalculator);

        // Promotion engine
        /** @var PromotionRepositoryInterface $promotionRepository */
        $promotionRepository = $container->get(PromotionRepositoryInterface::class);

        /** @var CouponRepositoryInterface $couponRepository */
        $couponRepository = $container->get(CouponRepositoryInterface::class);

        $promotionEngine = new PromotionEngine($promotionRepository, $couponRepository);
        $container->instance(PromotionServiceInterface::class, $promotionEngine);

        // Invoice renderer
        $settingsService = $container->has(SettingsServiceInterface::class)
            ? $container->get(SettingsServiceInterface::class)
            : null;

        /** @var SettingsServiceInterface|null $settingsService */
        $invoiceRenderer = new HtmlInvoiceRenderer($settingsService);
        $container->instance(InvoiceRendererInterface::class, $invoiceRenderer);

        // Invoice service
        /** @var OrderRepositoryInterface $orderRepository */
        $orderRepository = $container->get(OrderRepositoryInterface::class);

        /** @var OrderItemRepositoryInterface $orderItemRepository */
        $orderItemRepository = $container->get(OrderItemRepositoryInterface::class);

        /** @var InvoiceRepositoryInterface $invoiceRepository */
        $invoiceRepository = $container->get(InvoiceRepositoryInterface::class);

        $invoiceService = new InvoiceService($orderRepository, $orderItemRepository, $invoiceRepository, $invoiceRenderer);
        $container->instance(InvoiceServiceInterface::class, $invoiceService);

        // Digital delivery service (requires CmsKeyManager)
        if ($container->has(CmsKeyManager::class)) {
            /** @var CmsKeyManager $cmsKeyManager */
            $cmsKeyManager = $container->get(CmsKeyManager::class);

            /** @var DigitalAssetRepositoryInterface $digitalAssetRepository */
            $digitalAssetRepository = $container->get(DigitalAssetRepositoryInterface::class);

            /** @var ProductRepositoryInterface $productRepository */
            $productRepository = $container->get(ProductRepositoryInterface::class);

            $digitalDelivery = new DigitalDeliveryService(
                $digitalAssetRepository,
                $orderItemRepository,
                $productRepository,
                $cmsKeyManager,
                $connection,
                $commerceConfig,
                $auditLogger,
            );
            $container->instance(DigitalDeliveryServiceInterface::class, $digitalDelivery);

            // Order export service
            $container->instance(
                OrderExportServiceInterface::class,
                new OrderExportService($orderRepository, $orderItemRepository, $auditLogger),
            );

            // Checkout service (requires EventDispatcher)
            if ($eventDispatcher !== null) {
                /** @var PaymentGateway|null $paymentGateway */
                $paymentGateway = $container->has(PaymentGateway::class)
                    ? $container->get(PaymentGateway::class)
                    : null;

                $checkoutService = new CheckoutService(
                    $productRepository,
                    $orderRepository,
                    $orderItemRepository,
                    $promotionEngine,
                    $taxCalculator,
                    $invoiceService,
                    $digitalDelivery,
                    $connection,
                    $commerceConfig,
                    $eventDispatcher,
                    $paymentGateway,
                    $auditLogger,
                );
                $container->instance(CheckoutServiceInterface::class, $checkoutService);

                // Order service (internal, used by webhook handler)
                $orderService = new OrderService(
                    $orderRepository,
                    $invoiceService,
                    $digitalDelivery,
                    $connection,
                    $eventDispatcher,
                    $paymentGateway,
                    $auditLogger,
                );
                $container->instance(OrderService::class, $orderService);

                // Webhook handler (requires PaymentGateway)
                if ($paymentGateway !== null) {
                    $container->instance(
                        WebhookHandler::class,
                        new WebhookHandler($orderService, $orderRepository, $paymentGateway, $auditLogger),
                    );
                }
            }
        }
    }

    private function registerPermissions(ContainerInterface $container): void
    {
        if ($container->has(RoleRegistryInterface::class)) {
            /** @var RoleRegistryInterface $roleRegistry */
            $roleRegistry = $container->get(RoleRegistryInterface::class);
            CmsPermissions::register($roleRegistry);
        }
    }
}
