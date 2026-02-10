<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm;

use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Orm\Config\EncryptionConfig;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Contracts\SchemaBuilderInterface;
use Pulsar\Extension\Orm\Contracts\TransactionManagerInterface;
use Pulsar\Extension\Orm\Features\Encryption\AttributeColumnEncryptor;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Hydration\EntityHydrator;
use Pulsar\Extension\Orm\Features\Metadata\CachedMetadataRegistry;
use Pulsar\Extension\Orm\Features\Metadata\MetadataCompiler;
use Pulsar\Extension\Orm\Features\Persistence\AuditingPersister;
use Pulsar\Extension\Orm\Features\Persistence\GenericRepository;
use Pulsar\Extension\Orm\Features\Persistence\TransactionManager;
use Pulsar\Extension\Orm\Features\Schema\SchemaBuilder;
use Pulsar\Extension\Orm\Gateway\EntityManager;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\KeyProviderInterface;

/**
 * Service provider for the ORM extension.
 *
 * Binds all ORM services to the container based on configuration.
 */
final class OrmServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Config
        $container->bind(OrmConfig::class, static function () use ($container): OrmConfig {
            /** @var array<string, mixed> $configData */
            $configData = [];

            if ($container->has('config.orm')) {
                /** @var array<string, mixed> $configData */
                $configData = $container->get('config.orm');
            }

            return OrmConfig::fromArray($configData);
        });

        // Metadata
        $container->bind(MetadataCompiler::class, static function () use ($container): MetadataCompiler {
            /** @var OrmConfig $config */
            $config = $container->get(OrmConfig::class);

            return new MetadataCompiler($config);
        });

        $container->bind(MetadataRegistryInterface::class, static function () use ($container): MetadataRegistryInterface {
            /** @var MetadataCompiler $compiler */
            $compiler = $container->get(MetadataCompiler::class);

            return new CachedMetadataRegistry($compiler);
        });

        $container->bind(CachedMetadataRegistry::class, static function () use ($container): CachedMetadataRegistry {
            /** @var MetadataRegistryInterface $registry */
            $registry = $container->get(MetadataRegistryInterface::class);
            /** @var CachedMetadataRegistry $registry */

            return $registry;
        });

        // Encryption (optional)
        $container->bind(ColumnEncryptorInterface::class, static function () use ($container): ?ColumnEncryptorInterface {
            /** @var OrmConfig $config */
            $config = $container->get(OrmConfig::class);

            if (!$config->encryption->enabled) {
                return null;
            }

            if (!$container->has(EncryptorInterface::class) || !$container->has(KeyProviderInterface::class)) {
                return null;
            }

            /** @var EncryptorInterface $encryptor */
            $encryptor = $container->get(EncryptorInterface::class);

            /** @var KeyProviderInterface $keyProvider */
            $keyProvider = $container->get(KeyProviderInterface::class);

            return new AttributeColumnEncryptor($encryptor, $keyProvider, $config->encryption);
        });

        // Hydration
        $container->bind(EntityHydratorInterface::class, static function () use ($container): EntityHydratorInterface {
            /** @var MetadataRegistryInterface $registry */
            $registry = $container->get(MetadataRegistryInterface::class);

            /** @var ColumnEncryptorInterface|null $encryptor */
            $encryptor = $container->has(ColumnEncryptorInterface::class)
                ? $container->get(ColumnEncryptorInterface::class)
                : null;

            return new EntityHydrator($registry, $encryptor);
        });

        $container->bind(EntityDehydrator::class, static function () use ($container): EntityDehydrator {
            /** @var MetadataRegistryInterface $registry */
            $registry = $container->get(MetadataRegistryInterface::class);

            /** @var ColumnEncryptorInterface|null $encryptor */
            $encryptor = $container->has(ColumnEncryptorInterface::class)
                ? $container->get(ColumnEncryptorInterface::class)
                : null;

            return new EntityDehydrator($registry, $encryptor);
        });

        // Persistence
        $container->bind(AuditingPersister::class, static function () use ($container): AuditingPersister {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);

            /** @var MetadataRegistryInterface $registry */
            $registry = $container->get(MetadataRegistryInterface::class);

            /** @var EntityDehydrator $dehydrator */
            $dehydrator = $container->get(EntityDehydrator::class);

            /** @var AuditLoggerInterface|null $auditLogger */
            $auditLogger = $container->has(AuditLoggerInterface::class)
                ? $container->get(AuditLoggerInterface::class)
                : null;

            return new AuditingPersister($connection, $registry, $dehydrator, $auditLogger);
        });

        // Transaction manager
        $container->bind(TransactionManagerInterface::class, static function () use ($container): TransactionManagerInterface {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);

            return new TransactionManager($connection);
        });

        // Schema builder
        $container->bind(SchemaBuilderInterface::class, static function () use ($container): SchemaBuilderInterface {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);

            return new SchemaBuilder($connection);
        });

        // Entity manager (main gateway)
        $container->bind(EntityManager::class, static function () use ($container): EntityManager {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);

            /** @var MetadataRegistryInterface $registry */
            $registry = $container->get(MetadataRegistryInterface::class);

            /** @var EntityHydratorInterface $hydrator */
            $hydrator = $container->get(EntityHydratorInterface::class);

            /** @var AuditingPersister $persister */
            $persister = $container->get(AuditingPersister::class);

            /** @var TransactionManagerInterface $txManager */
            $txManager = $container->get(TransactionManagerInterface::class);

            return new EntityManager($connection, $registry, $hydrator, $persister, $txManager);
        });
    }

    public function provides(): array
    {
        return [
            OrmConfig::class,
            MetadataCompiler::class,
            MetadataRegistryInterface::class,
            CachedMetadataRegistry::class,
            ColumnEncryptorInterface::class,
            EntityHydratorInterface::class,
            EntityDehydrator::class,
            AuditingPersister::class,
            TransactionManagerInterface::class,
            SchemaBuilderInterface::class,
            EntityManager::class,
        ];
    }
}
