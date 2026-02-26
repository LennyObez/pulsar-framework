<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\ABTest\ExperimentRepositoryInterface;
use Pulsar\Extension\Cms\Collaboration\CollaborationRepositoryInterface;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ApiKeyRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\CouponRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\CustomerRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\DigitalAssetRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\InvoiceRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderItemRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\OrderRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductVariantRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\EventStore\ContentEventStoreInterface;
use Pulsar\Extension\Cms\EventStore\ContentSnapshotServiceInterface;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Persistence\DbApiKeyRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbCmsPluginRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbCmsUserRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbCollaborationRepository;
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
use Pulsar\Extension\Cms\Internal\Persistence\DbCustomerRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbDigitalAssetRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbEditorialReviewRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbExperimentRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbFieldRegistryRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbInvoiceRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbLinkHealthRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbMediaRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbMenuRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbOrderItemRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbOrderRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbProductRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbProductVariantRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbPromotionRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbRedirectRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbSearchAnalyticsRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbSettingsRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbTaxonomyRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbThemeRepository;
use Pulsar\Extension\Cms\LiveCss\CssOverrideRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Plugins\CmsPluginRepositoryInterface;
use Pulsar\Extension\Cms\Search\SearchAnalyticsRepositoryInterface;
use Pulsar\Extension\Cms\Seo\LinkHealthRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;
use Pulsar\Extension\Cms\Users\CmsUserRepositoryInterface;

/**
 * Binds all CMS repository interfaces to their database-backed implementations.
 */
#[Internal(reason: 'CMS service wiring — use interfaces for public API')]
final readonly class CmsRepositoryProvider
{
    public function register(ContainerInterface $container): void
    {
        if (!$container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var ConnectionInterface $connection */
        $connection = $container->get(ConnectionInterface::class);

        /** @var string|null $tenantId */
        $tenantId = null;

        $container->instance(
            ContentRepositoryInterface::class,
            new DbContentRepository($connection, $tenantId),
        );

        $container->instance(
            ContentTranslationRepositoryInterface::class,
            new DbContentTranslationRepository($connection, $tenantId),
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
            new DbRedirectRepository($connection, $tenantId),
        );

        $container->instance(
            TaxonomyRepositoryInterface::class,
            new DbTaxonomyRepository($connection, $tenantId),
        );

        $container->instance(
            MenuRepositoryInterface::class,
            new DbMenuRepository($connection, $tenantId),
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
            new DbSettingsRepository($connection, $tenantId),
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
            ProductVariantRepositoryInterface::class,
            new DbProductVariantRepository($connection),
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

        $container->instance(
            CustomerRepositoryInterface::class,
            new DbCustomerRepository($connection),
        );

        $container->instance(
            ApiKeyRepositoryInterface::class,
            new DbApiKeyRepository($connection),
        );

        // A/B Testing repository
        $container->instance(
            ExperimentRepositoryInterface::class,
            new DbExperimentRepository($connection),
        );

        // Collaboration repository
        $container->instance(
            CollaborationRepositoryInterface::class,
            new DbCollaborationRepository($connection),
        );
    }
}
