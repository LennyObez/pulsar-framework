<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\Event\CmsReady;
use Pulsar\Extension\Cms\Http\Controller\Admin\ContentController as AdminContentController;
use Pulsar\Extension\Cms\Http\Controller\Admin\DashboardController as AdminDashboardController;
use Pulsar\Extension\Cms\Http\Controller\Admin\FieldController;
use Pulsar\Extension\Cms\Http\Controller\Admin\MenuController as AdminMenuController;
use Pulsar\Extension\Cms\Http\Controller\Admin\ReviewController;
use Pulsar\Extension\Cms\Http\Controller\Admin\RevisionController;
use Pulsar\Extension\Cms\Http\Controller\Admin\SettingsController;
use Pulsar\Extension\Cms\Http\Controller\Admin\TaxonomyController as AdminTaxonomyController;
use Pulsar\Extension\Cms\Http\Controller\ContentController;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGenerator;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGeneratorInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Routing\RouterInterface;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * CMS extension for regulated, mission-critical domains.
 *
 * Provides content management, taxonomy, navigation, editorial workflow,
 * custom fields, content locking, event sourcing, atomic snapshots,
 * safe HTML sanitization, and full-page caching with tag-based invalidation.
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
        if ($container->has(ConfigManagerInterface::class)) {
            /** @var ConfigManagerInterface $configManager */
            $configManager = $container->get(ConfigManagerInterface::class);
            $configPath = $configManager->configPath();

            if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'cms.php')) {
                /** @psalm-suppress UnresolvableInclude */
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
            && $container->has(\Pulsar\Extension\Cms\Content\ContentRepositoryInterface::class)
            && $container->has(\Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface::class)
        ) {
            $container->instance(BreadcrumbGeneratorInterface::class, new BreadcrumbGenerator(
                $container->get(\Pulsar\Extension\Cms\Content\ContentRepositoryInterface::class),
                $container->get(\Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface::class),
                $container->get(CmsConfig::class),
            ));
        }
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        /** @var CmsConfig $config */
        $config = $container->get(CmsConfig::class);

        $this->registerPublicRoutes($router, $config);
        $this->registerAdminRoutes($router);
    }

    #[Override]
    public function postBoot(ContainerInterface $container): void
    {
        // Warm critical caches: settings
        if ($container->has(SettingsServiceInterface::class) && $container->has(TaggedCacheInterface::class)) {
            /** @var SettingsServiceInterface $settings */
            $settings = $container->get(SettingsServiceInterface::class);

            /** @var CmsConfig $config */
            $config = $container->get(CmsConfig::class);

            // Pre-load settings for the default locale
            $settings->getAll($config->defaultLocale);
        }

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

    private function registerPublicRoutes(RouterInterface $router, CmsConfig $config): void
    {
        // Public content rendering — catch-all route for locale-prefixed and default paths
        // Locale-aware routing: /{locale}/{path} or /{path} for default locale
        foreach ($config->supportedLocales as $locale) {
            if ($locale === $config->defaultLocale && !$config->defaultLocaleInUrl) {
                // Default locale served without prefix: /{path}
                $router->get('/{path}', [ContentController::class, 'show'], 'cms.content.show');
            } else {
                // Other locales served with prefix: /{locale}/{path}
                $router->get("/{$locale}/{path}", [ContentController::class, 'show'], "cms.content.show.{$locale}");
            }
        }
    }

    private function registerAdminRoutes(RouterInterface $router): void
    {
        $prefix = '/admin/cms';

        // Dashboard
        $router->get($prefix, [AdminDashboardController::class, 'index'], 'cms.admin.dashboard');

        // Content CRUD
        $router->get("{$prefix}/content", [AdminContentController::class, 'index'], 'cms.admin.content.index');
        $router->post("{$prefix}/content", [AdminContentController::class, 'create'], 'cms.admin.content.create');
        $router->get("{$prefix}/content/{id}", [AdminContentController::class, 'show'], 'cms.admin.content.show');
        $router->put("{$prefix}/content/{id}", [AdminContentController::class, 'update'], 'cms.admin.content.update');
        $router->delete("{$prefix}/content/{id}", [AdminContentController::class, 'delete'], 'cms.admin.content.delete');

        // Content locale translations
        $router->post("{$prefix}/content/{id}/translations", [AdminContentController::class, 'addTranslation'], 'cms.admin.content.add_translation');

        // Content workflow actions
        $router->post("{$prefix}/content/{id}/publish", [AdminContentController::class, 'publish'], 'cms.admin.content.publish');
        $router->post("{$prefix}/content/{id}/archive", [AdminContentController::class, 'archive'], 'cms.admin.content.archive');
        $router->post("{$prefix}/content/{id}/schedule", [AdminContentController::class, 'schedule'], 'cms.admin.content.schedule');
        $router->post("{$prefix}/content/{id}/submit-review", [AdminContentController::class, 'submitReview'], 'cms.admin.content.submit_review');

        // Content locking
        $router->post("{$prefix}/content/{id}/lock", [AdminContentController::class, 'acquireLock'], 'cms.admin.content.lock');
        $router->delete("{$prefix}/content/{id}/lock", [AdminContentController::class, 'releaseLock'], 'cms.admin.content.unlock');

        // Revisions
        $router->get("{$prefix}/content/{contentId}/revisions", [RevisionController::class, 'index'], 'cms.admin.revisions.index');
        $router->post("{$prefix}/content/{contentId}/revisions/{revisionId}/restore", [RevisionController::class, 'restore'], 'cms.admin.revisions.restore');

        // Editorial reviews
        $router->get("{$prefix}/reviews", [ReviewController::class, 'index'], 'cms.admin.reviews.index');
        $router->post("{$prefix}/reviews/{reviewId}/approve", [ReviewController::class, 'approve'], 'cms.admin.reviews.approve');
        $router->post("{$prefix}/reviews/{reviewId}/reject", [ReviewController::class, 'reject'], 'cms.admin.reviews.reject');

        // Taxonomies
        $router->get("{$prefix}/taxonomies", [AdminTaxonomyController::class, 'index'], 'cms.admin.taxonomies.index');
        $router->post("{$prefix}/taxonomies", [AdminTaxonomyController::class, 'create'], 'cms.admin.taxonomies.create');
        $router->get("{$prefix}/taxonomies/{slug}", [AdminTaxonomyController::class, 'show'], 'cms.admin.taxonomies.show');
        $router->put("{$prefix}/taxonomies/{slug}", [AdminTaxonomyController::class, 'update'], 'cms.admin.taxonomies.update');
        $router->delete("{$prefix}/taxonomies/{slug}", [AdminTaxonomyController::class, 'delete'], 'cms.admin.taxonomies.delete');

        // Menus
        $router->get("{$prefix}/menus", [AdminMenuController::class, 'index'], 'cms.admin.menus.index');
        $router->post("{$prefix}/menus", [AdminMenuController::class, 'create'], 'cms.admin.menus.create');
        $router->get("{$prefix}/menus/{location}", [AdminMenuController::class, 'show'], 'cms.admin.menus.show');
        $router->put("{$prefix}/menus/{location}", [AdminMenuController::class, 'update'], 'cms.admin.menus.update');
        $router->delete("{$prefix}/menus/{location}", [AdminMenuController::class, 'delete'], 'cms.admin.menus.delete');

        // Custom fields
        $router->get("{$prefix}/fields/{contentType}", [FieldController::class, 'index'], 'cms.admin.fields.index');
        $router->post("{$prefix}/fields/{contentType}", [FieldController::class, 'create'], 'cms.admin.fields.create');
        $router->put("{$prefix}/fields/{contentType}/{fieldId}", [FieldController::class, 'update'], 'cms.admin.fields.update');
        $router->delete("{$prefix}/fields/{contentType}/{fieldId}", [FieldController::class, 'delete'], 'cms.admin.fields.delete');

        // Settings
        $router->get("{$prefix}/settings/{group}", [SettingsController::class, 'show'], 'cms.admin.settings.show');
        $router->put("{$prefix}/settings/{group}", [SettingsController::class, 'update'], 'cms.admin.settings.update');
    }
}
