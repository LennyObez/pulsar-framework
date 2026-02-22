<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Cms\ABTest\ExperimentRepositoryInterface;
use Pulsar\Extension\Cms\BlockEditor\BlockRenderer;
use Pulsar\Extension\Cms\BlockEditor\BlockTypeRegistry;
use Pulsar\Extension\Cms\Collaboration\CollaborationRepositoryInterface;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Comments\CommentServiceInterface;
use Pulsar\Extension\Cms\Commerce\CheckoutServiceInterface;
use Pulsar\Extension\Cms\Commerce\CouponRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\DigitalAssetRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\DigitalDeliveryServiceInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderExportServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductVariantRepositoryInterface;
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
use Pulsar\Extension\Cms\Dashboard\DashboardService;
use Pulsar\Extension\Cms\EventStore\ContentEventStoreInterface;
use Pulsar\Extension\Cms\EventStore\ContentSnapshotServiceInterface;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeRegistryInterface;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Http\Controller\Admin\BackupController;
use Pulsar\Extension\Cms\Http\Controller\Admin\BulkOperationsController;
use Pulsar\Extension\Cms\Http\Controller\Admin\CommentController as AdminCommentController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ContentController as AdminContentController;
use Pulsar\Extension\Cms\Http\Controller\Admin\DashboardController;
use Pulsar\Extension\Cms\Http\Controller\Admin\DigitalAssetController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ExperimentController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ExportController;
use Pulsar\Extension\Cms\Http\Controller\Admin\FieldController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ImportController;
use Pulsar\Extension\Cms\Http\Controller\Admin\InvoiceController;
use Pulsar\Extension\Cms\Http\Controller\Admin\LinkHealthController;
use Pulsar\Extension\Cms\Http\Controller\Admin\LiveCssController;
use Pulsar\Extension\Cms\Http\Controller\Admin\MediaController as AdminMediaController;
use Pulsar\Extension\Cms\Http\Controller\Admin\MenuController as AdminMenuController;
use Pulsar\Extension\Cms\Http\Controller\Admin\OrderController;
use Pulsar\Extension\Cms\Http\Controller\Admin\PluginController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ProductController;
use Pulsar\Extension\Cms\Http\Controller\Admin\PromotionController;
use Pulsar\Extension\Cms\Http\Controller\Admin\RedirectController as AdminRedirectController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ReviewController;
use Pulsar\Extension\Cms\Http\Controller\Admin\RevisionController;
use Pulsar\Extension\Cms\Http\Controller\Admin\SearchAnalyticsController;
use Pulsar\Extension\Cms\Http\Controller\Admin\SettingsController;
use Pulsar\Extension\Cms\Http\Controller\Admin\SiteDefinitionController;
use Pulsar\Extension\Cms\Http\Controller\Admin\SitemapController as AdminSitemapController;
use Pulsar\Extension\Cms\Http\Controller\Admin\TaxonomyController as AdminTaxonomyController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ThemeController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ToolsController;
use Pulsar\Extension\Cms\Http\Controller\Admin\TwoFactorController;
use Pulsar\Extension\Cms\Http\Controller\Admin\UserController;
use Pulsar\Extension\Cms\Http\Controller\Api\CollaborationApiController;
use Pulsar\Extension\Cms\Internal\ABTest\ExperimentService;
use Pulsar\Extension\Cms\Internal\ABTest\TrafficSplitter;
use Pulsar\Extension\Cms\Internal\Collaboration\CollaborationService;
use Pulsar\Extension\Cms\Internal\Security\ClientFingerprintResolver;
use Pulsar\Extension\Cms\Internal\Security\CmsKeyManager;
use Pulsar\Extension\Cms\Internal\Security\QrCodeEncoder;
use Pulsar\Extension\Cms\Internal\Security\SafeHttpClient;
use Pulsar\Extension\Cms\LiveCss\CspHashComputerInterface;
use Pulsar\Extension\Cms\LiveCss\CssOverrideRepositoryInterface;
use Pulsar\Extension\Cms\LiveCss\CssValidatorInterface;
use Pulsar\Extension\Cms\LiveCss\LiveCssServiceInterface;
use Pulsar\Extension\Cms\LiveCss\ThemeTokenResolverInterface;
use Pulsar\Extension\Cms\Media\ImageProcessorInterface;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaServiceInterface;
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

use function getcwd;

/**
 * Orchestrator that delegates to focused sub-providers for CMS service wiring.
 *
 * Sub-providers:
 *  - CmsRepositoryProvider       — all repository interface → implementation bindings
 *  - CmsCoreServiceProvider      — settings, taxonomy, media, comments, search, SEO, tools, security
 *  - CmsThemePluginProvider      — theme manager, plugin manager, hook engine, live CSS
 *  - CmsCommerceProvider         — checkout, tax, promotions, invoicing, digital delivery
 *  - CmsAdminControllerProvider  — all admin controller bindings
 */
#[Internal(reason: 'CMS service wiring — use interfaces for public API')]
final class CmsServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // 1. Repository layer (DB-backed implementations)
        new CmsRepositoryProvider()->register($container);

        // 2. Core services (settings, media, SEO, tools, security, workflow, content controller)
        new CmsCoreServiceProvider()->register($container);

        // 3. Theme and plugin systems (includes live CSS)
        new CmsThemePluginProvider()->register($container);

        // 4. Commerce subsystem (conditional on config)
        if ($container->has(ConnectionInterface::class)) {
            /** @var CmsConfig $config */
            $config = $container->has(CmsConfig::class)
                ? $container->get(CmsConfig::class)
                : new CmsConfig();

            if ($config->commerce !== null) {
                /** @var ConnectionInterface $connection */
                $connection = $container->get(ConnectionInterface::class);

                /** @var AuditLoggerInterface|null $auditLogger */
                $auditLogger = $container->has(AuditLoggerInterface::class)
                    ? $container->get(AuditLoggerInterface::class)
                    : null;

                /** @var EventDispatcherInterface|null $eventDispatcher */
                $eventDispatcher = $container->has(EventDispatcherInterface::class)
                    ? $container->get(EventDispatcherInterface::class)
                    : null;

                new CmsCommerceProvider()->register(
                    $container,
                    $connection,
                    $config->commerce,
                    $eventDispatcher,
                    $auditLogger,
                );
            }

            // 5. Admin controllers (back-office)
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);

            /** @var AuditLoggerInterface|null $auditLogger */
            $auditLogger = $container->has(AuditLoggerInterface::class)
                ? $container->get(AuditLoggerInterface::class)
                : null;

            new CmsAdminControllerProvider()->register($container, $connection, $config, $auditLogger);
        }

        // 6. Permissions and commands
        $this->registerPermissions($container);
        $this->registerCommands($container);
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
            // Block editor
            BlockTypeRegistry::class,
            BlockRenderer::class,
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
            ProductVariantRepositoryInterface::class,
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
            // Publishing
            \Pulsar\Extension\Cms\Publishing\ChannelRegistry::class,
            \Pulsar\Extension\Cms\Internal\Publishing\PublishingOrchestrator::class,
            // Collaboration
            CollaborationRepositoryInterface::class,
            CollaborationService::class,
            CollaborationApiController::class,
            // A/B Testing
            ExperimentRepositoryInterface::class,
            ExperimentService::class,
            TrafficSplitter::class,
            ExperimentController::class,
            // Security
            SafeHttpClient::class,
            ClientFingerprintResolver::class,
            CmsKeyManager::class,
            QrCodeEncoder::class,
            Command\CmsServeCommand::class,
            Command\CmsPublishScheduledCommand::class,
            // Admin controllers
            DashboardController::class,
            DashboardService::class,
            AdminContentController::class,
            BulkOperationsController::class,
            AdminMenuController::class,
            AdminTaxonomyController::class,
            AdminMediaController::class,
            AdminCommentController::class,
            AdminRedirectController::class,
            AdminSitemapController::class,
            FieldController::class,
            ReviewController::class,
            RevisionController::class,
            SettingsController::class,
            ThemeController::class,
            PluginController::class,
            UserController::class,
            TwoFactorController::class,
            ProductController::class,
            OrderController::class,
            PromotionController::class,
            DigitalAssetController::class,
            InvoiceController::class,
            LiveCssController::class,
            ExportController::class,
            ImportController::class,
            SiteDefinitionController::class,
            BackupController::class,
            ToolsController::class,
            SearchAnalyticsController::class,
            LinkHealthController::class,
            Http\Controller\ContentController::class,
            \Pulsar\Extension\Cms\I18n\HreflangGenerator::class,
            // REST API controllers
            Http\Controller\Api\ContentApiController::class,
            Http\Controller\Api\TaxonomyApiController::class,
            Http\Controller\Api\MediaApiController::class,
            Http\Controller\Api\CommerceApiController::class,
            Http\Middleware\CmsApiContentNegotiationMiddleware::class,
            // AI content assistant (conditional)
            AI\LlmProviderInterface::class,
            AI\ContentAssistant::class,
            Http\Controller\Api\AiAssistantApiController::class,
        ];
    }

    private function registerCommands(ContainerInterface $container): void
    {
        /** @var string $basePath */
        $basePath = $container->has('app.base_path')
            ? $container->get('app.base_path')
            : (getcwd() ?: '.');

        $container->instance(
            Command\CmsServeCommand::class,
            new Command\CmsServeCommand($basePath),
        );

        if ($container->has(ContentRepositoryInterface::class)) {
            /** @var ContentRepositoryInterface $contentRepo */
            $contentRepo = $container->get(ContentRepositoryInterface::class);

            $container->instance(
                Command\CmsPublishScheduledCommand::class,
                new Command\CmsPublishScheduledCommand($contentRepo),
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
