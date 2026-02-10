<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Comments\CommentServiceInterface;
use Pulsar\Extension\Cms\Commerce\CouponRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\DigitalAssetRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderExportServiceInterface;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionRepositoryInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\PublishingStateMachine;
use Pulsar\Extension\Cms\Content\RevisionService;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Extension\Cms\Dashboard\DashboardService;
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
use Pulsar\Extension\Cms\Internal\ABTest\ExperimentService;
use Pulsar\Extension\Cms\Internal\Commerce\OrderService;
use Pulsar\Extension\Cms\Internal\Security\CmsRateLimiter;
use Pulsar\Extension\Cms\Internal\Security\QrCodeEncoder;
use Pulsar\Extension\Cms\LiveCss\CssValidatorInterface;
use Pulsar\Extension\Cms\LiveCss\LiveCssServiceInterface;
use Pulsar\Extension\Cms\LiveCss\ThemeTokenResolverInterface;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaServiceInterface;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Plugins\CmsPluginManagerInterface;
use Pulsar\Extension\Cms\Search\SearchServiceInterface;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;
use Pulsar\Extension\Cms\Seo\RedirectManagerInterface;
use Pulsar\Extension\Cms\Seo\SitemapGeneratorInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyServiceInterface;
use Pulsar\Extension\Cms\Themes\ThemeManagerInterface;
use Pulsar\Extension\Cms\Tools\BackupServiceInterface;
use Pulsar\Extension\Cms\Tools\ImportExportServiceInterface;
use Pulsar\Extension\Cms\Tools\ToolsServiceInterface;
use Pulsar\Extension\Cms\Users\CmsUserRepositoryInterface;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\View\Engine\TemplateEngineInterface;

/**
 * Binds all admin (back-office) controllers for the CMS.
 */
#[Internal(reason: 'CMS service wiring — use interfaces for public API')]
final readonly class CmsAdminControllerProvider
{
    public function register(
        ContainerInterface $container,
        ConnectionInterface $connection,
        CmsConfig $config,
        ?AuditLoggerInterface $auditLogger,
    ): void {
        // === Shared dependencies ===

        /** @var GateInterface|null $gate */
        $gate = $container->has(GateInterface::class)
            ? $container->get(GateInterface::class)
            : null;

        /** @var TemplateEngineInterface|null $templateEngine */
        $templateEngine = $container->has(TemplateEngineInterface::class)
            ? $container->get(TemplateEngineInterface::class)
            : null;

        // Rate limiter (optional — only when cache is available)
        /** @var CmsRateLimiter|null $rateLimiter */
        $rateLimiter = $container->has(TaggedCacheInterface::class)
            ? new CmsRateLimiter($container->get(TaggedCacheInterface::class))
            : null;

        // DashboardService (always available)
        $dashboardService = new DashboardService([]);
        $container->instance(DashboardService::class, $dashboardService);

        // PublishingStateMachine (utility, no deps)
        $publishingStateMachine = new PublishingStateMachine();

        // TOTP services (for TwoFactorController)
        $totpGenerator = new TotpGenerator();
        $totpVerifier = new TotpVerifier($totpGenerator);
        $recoveryCodeGenerator = new RecoveryCodeGenerator();

        // === DashboardController (all params nullable — always registrable) ===

        /** @var EditorialWorkflowServiceInterface|null $workflowService */
        $workflowService = $container->has(EditorialWorkflowServiceInterface::class)
            ? $container->get(EditorialWorkflowServiceInterface::class)
            : null;

        /** @var SettingsServiceInterface|null $settingsService */
        $settingsService = $container->has(SettingsServiceInterface::class)
            ? $container->get(SettingsServiceInterface::class)
            : null;

        $container->instance(
            DashboardController::class,
            new DashboardController($dashboardService, $workflowService, $settingsService, $gate, $templateEngine),
        );

        // Other admin controllers require GateInterface for authorization
        if ($gate === null) {
            return;
        }

        // === Always-registrable controllers (all deps unconditionally bound) ===

        $container->instance(
            FieldController::class,
            new FieldController(
                $container->get(FieldRegistryRepositoryInterface::class),
                $gate,
                $templateEngine,
            ),
        );

        $container->instance(
            AdminMenuController::class,
            new AdminMenuController(
                $container->get(MenuRepositoryInterface::class),
                $gate,
                $config,
                $templateEngine,
            ),
        );

        $container->instance(
            AdminTaxonomyController::class,
            new AdminTaxonomyController(
                $container->get(TaxonomyRepositoryInterface::class),
                $gate,
                $config,
                $templateEngine,
            ),
        );

        $container->instance(
            BulkOperationsController::class,
            new BulkOperationsController(
                $container->get(ContentRepositoryInterface::class),
                $container->get(TaxonomyServiceInterface::class),
                $gate,
                $config,
                $templateEngine,
            ),
        );

        $container->instance(
            AdminMediaController::class,
            new AdminMediaController(
                $container->get(MediaRepositoryInterface::class),
                $container->get(MediaServiceInterface::class),
                $gate,
                $templateEngine,
            ),
        );

        $container->instance(
            SearchAnalyticsController::class,
            new SearchAnalyticsController(
                $container->get(SearchServiceInterface::class),
                $gate,
                $templateEngine,
            ),
        );

        $container->instance(
            AdminRedirectController::class,
            new AdminRedirectController(
                $container->get(RedirectManagerInterface::class),
                $gate,
                $templateEngine,
            ),
        );

        $container->instance(
            AdminSitemapController::class,
            new AdminSitemapController(
                $container->get(SitemapGeneratorInterface::class),
                $gate,
                $templateEngine,
            ),
        );

        $container->instance(
            ProductController::class,
            new ProductController(
                $container->get(ProductRepositoryInterface::class),
                $gate,
                $templateEngine,
            ),
        );

        $container->instance(
            PromotionController::class,
            new PromotionController(
                $container->get(PromotionRepositoryInterface::class),
                $container->get(CouponRepositoryInterface::class),
                $gate,
                $templateEngine,
            ),
        );

        $container->instance(
            DigitalAssetController::class,
            new DigitalAssetController(
                $container->get(DigitalAssetRepositoryInterface::class),
                $container->get(ProductRepositoryInterface::class),
                $container->get(MediaDiskInterface::class),
                $gate,
                $templateEngine,
            ),
        );

        $container->instance(
            ToolsController::class,
            new ToolsController(
                $container->get(ToolsServiceInterface::class),
                $gate,
                $templateEngine,
            ),
        );

        $container->instance(
            BackupController::class,
            new BackupController(
                $container->get(BackupServiceInterface::class),
                $rateLimiter,
                $gate,
                $templateEngine,
            ),
        );

        $container->instance(
            UserController::class,
            new UserController(
                $container->get(CmsUserRepositoryInterface::class),
                $gate,
                $auditLogger,
                $templateEngine,
            ),
        );

        $container->instance(
            TwoFactorController::class,
            new TwoFactorController(
                $totpGenerator,
                $totpVerifier,
                $recoveryCodeGenerator,
                $container->get(QrCodeEncoder::class),
                $rateLimiter,
                $gate,
                $auditLogger,
                $templateEngine,
            ),
        );

        // === Conditionally-registrable controllers (deps may not be bound) ===

        if ($settingsService !== null) {
            $container->instance(
                SettingsController::class,
                new SettingsController($settingsService, $gate, $config, $templateEngine),
            );

            $container->instance(
                RobotsController::class,
                new RobotsController($settingsService, $gate, $templateEngine),
            );
        }

        if ($container->has(CommentServiceInterface::class)) {
            $container->instance(
                AdminCommentController::class,
                new AdminCommentController(
                    $container->get(CommentRepositoryInterface::class),
                    $container->get(CommentServiceInterface::class),
                    $gate,
                    $templateEngine,
                ),
            );
        }

        if ($container->has(ThemeManagerInterface::class)) {
            /** @var ThemeManagerInterface $themeManager */
            $themeManager = $container->get(ThemeManagerInterface::class);

            $container->instance(
                ThemeController::class,
                new ThemeController($themeManager, $rateLimiter, $gate, $templateEngine),
            );

            $container->instance(
                LiveCssController::class,
                new LiveCssController(
                    $container->get(LiveCssServiceInterface::class),
                    $container->get(CssValidatorInterface::class),
                    $container->get(ThemeTokenResolverInterface::class),
                    $themeManager,
                    $gate,
                    $templateEngine,
                ),
            );
        }

        if ($container->has(CmsPluginManagerInterface::class) && $settingsService !== null) {
            $container->instance(
                PluginController::class,
                new PluginController(
                    $container->get(CmsPluginManagerInterface::class),
                    $settingsService,
                    $rateLimiter,
                    $gate,
                    $templateEngine,
                ),
            );
        }

        if ($container->has(LinkHealthServiceInterface::class)) {
            $container->instance(
                LinkHealthController::class,
                new LinkHealthController(
                    $container->get(LinkHealthServiceInterface::class),
                    $gate,
                    $templateEngine,
                ),
            );
        }

        if ($container->has(ImportExportServiceInterface::class)) {
            /** @var ImportExportServiceInterface $importExport */
            $importExport = $container->get(ImportExportServiceInterface::class);

            $container->instance(
                ExportController::class,
                new ExportController(
                    $importExport,
                    $gate,
                    $container->get(ContentRepositoryInterface::class),
                    $container->get(ContentTranslationRepositoryInterface::class),
                    $container->get(ContentBlockRepositoryInterface::class),
                    $templateEngine,
                ),
            );
            $container->instance(
                ImportController::class,
                new ImportController(
                    $importExport,
                    $gate,
                    $container->get(ContentRepositoryInterface::class),
                    $container->get(ContentTranslationRepositoryInterface::class),
                    $templateEngine,
                ),
            );
            $container->instance(SiteDefinitionController::class, new SiteDefinitionController($importExport, $gate, $templateEngine));
        }

        if ($container->has(InvoiceServiceInterface::class)) {
            $container->instance(
                InvoiceController::class,
                new InvoiceController(
                    $container->get(InvoiceServiceInterface::class),
                    $gate,
                ),
            );
        }

        if ($container->has(OrderService::class) && $container->has(OrderExportServiceInterface::class)) {
            $container->instance(
                OrderController::class,
                new OrderController(
                    $container->get(OrderRepositoryInterface::class),
                    $container->get(OrderItemRepositoryInterface::class),
                    $container->get(OrderService::class),
                    $container->get(OrderExportServiceInterface::class),
                    $gate,
                    $templateEngine,
                ),
            );
        }

        // ExperimentController (A/B testing)
        if ($container->has(ExperimentService::class)) {
            $container->instance(
                ExperimentController::class,
                new ExperimentController(
                    $container->get(ExperimentService::class),
                    $gate,
                    $templateEngine,
                ),
            );
        }

        // AdminContentController — needs many conditional deps
        if (
            $container->has(ContentLockServiceInterface::class)
            && $container->has(EditorialWorkflowServiceInterface::class)
            && $container->has(SafeHtmlPolicy::class)
        ) {
            $container->instance(
                AdminContentController::class,
                new AdminContentController(
                    $container->get(ContentRepositoryInterface::class),
                    $container->get(ContentTranslationRepositoryInterface::class),
                    $container->get(ContentBlockRepositoryInterface::class),
                    $container->get(FieldRegistryRepositoryInterface::class),
                    $publishingStateMachine,
                    $container->get(ContentLockServiceInterface::class),
                    $container->get(EditorialWorkflowServiceInterface::class),
                    $container->get(SafeHtmlPolicy::class),
                    $gate,
                    $config,
                    $templateEngine,
                ),
            );
        }

        // RevisionController
        if ($container->has(RevisionService::class)) {
            $container->instance(
                RevisionController::class,
                new RevisionController(
                    $container->get(ContentRepositoryInterface::class),
                    $container->get(ContentRevisionRepositoryInterface::class),
                    $container->get(RevisionService::class),
                    $gate,
                    $config,
                    $templateEngine,
                ),
            );
        }

        // ReviewController
        if ($workflowService !== null) {
            $container->instance(
                ReviewController::class,
                new ReviewController(
                    $workflowService,
                    $container->get(ContentRepositoryInterface::class),
                    $publishingStateMachine,
                    $gate,
                    $templateEngine,
                ),
            );
        }
    }
}
