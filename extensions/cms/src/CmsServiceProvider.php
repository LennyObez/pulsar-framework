<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms;

use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Cms\Config\CmsPermissions;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\EventStore\ContentEventStoreInterface;
use Pulsar\Extension\Cms\EventStore\ContentSnapshotServiceInterface;
use Pulsar\Extension\Cms\FieldRegistry\ContentTypeRegistryInterface;
use Pulsar\Extension\Cms\FieldRegistry\FieldRegistryRepositoryInterface;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentBlockRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentEventRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentLockRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentRevisionRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentSnapshotRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbContentTranslationRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbEditorialReviewRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbFieldRegistryRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbMenuRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbRedirectRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbSettingsRepository;
use Pulsar\Extension\Cms\Internal\Persistence\DbTaxonomyRepository;
use Pulsar\Extension\Cms\Navigation\BreadcrumbGeneratorInterface;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyServiceInterface;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;

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
    }

    private function bindServices(ContainerInterface $container): void
    {
        if (!$container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var ConnectionInterface $connection */
        $connection = $container->get(ConnectionInterface::class);

        // SettingsServiceInterface needs explicit binding because it takes
        // a ConnectionInterface and AuditLoggerInterface — not auto-wirable
        // due to the optional $tenantId constructor parameter.
        if ($container->has(\Pulsar\Audit\AuditLoggerInterface::class)) {
            /** @var \Pulsar\Audit\AuditLoggerInterface $auditLogger */
            $auditLogger = $container->get(\Pulsar\Audit\AuditLoggerInterface::class);

            $container->instance(
                SettingsServiceInterface::class,
                new \Pulsar\Extension\Cms\Settings\SettingsService($connection, $auditLogger),
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
