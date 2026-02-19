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
use Pulsar\Extension\Cms\Internal\Persistence\DbEditorialReviewRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbFieldRegistryRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbLinkHealthRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbMediaRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbMenuRepository;
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

        // Tools stack
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
