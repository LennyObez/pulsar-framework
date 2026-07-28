<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\Orm\Config\OrmConfig;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\MetadataRegistryInterface;
use Pulsar\Extension\Orm\Contracts\SchemaBuilderInterface;
use Pulsar\Extension\Orm\Contracts\TenantScopeInterface;
use Pulsar\Extension\Orm\Contracts\TransactionManagerInterface;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Metadata\CachedMetadataRegistry;
use Pulsar\Extension\Orm\Features\Metadata\MetadataCompiler;
use Pulsar\Extension\Orm\Features\Persistence\AuditingPersister;
use Pulsar\Extension\Orm\Features\Tenancy\TenantInsertEnricher;
use Pulsar\Extension\Orm\Gateway\EntityManager;
use Pulsar\Extension\Orm\OrmServiceProvider;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\KeyProviderInterface;

use function array_unique;
use function array_values;
use function class_exists;
use function count;
use function interface_exists;
use function sprintf;

#[CoversClass(OrmServiceProvider::class)]
final class OrmServiceProviderTest extends TestCase
{
    #[Test]
    public function providesReturnsExpectedServiceIds(): void
    {
        $provider = new OrmServiceProvider();
        $provides = $provider->provides();

        self::assertContains(OrmConfig::class, $provides);
        self::assertContains(MetadataCompiler::class, $provides);
        self::assertContains(MetadataRegistryInterface::class, $provides);
        self::assertContains(CachedMetadataRegistry::class, $provides);
        self::assertContains(ColumnEncryptorInterface::class, $provides);
        self::assertContains(EntityHydratorInterface::class, $provides);
        self::assertContains(EntityDehydrator::class, $provides);
        self::assertContains(AuditingPersister::class, $provides);
        self::assertContains(TransactionManagerInterface::class, $provides);
        self::assertContains(SchemaBuilderInterface::class, $provides);
        self::assertContains(EntityManager::class, $provides);
    }

    #[Test]
    public function providesEntriesAreUniqueAndResolvable(): void
    {
        // Replaces an assertCount() on a literal — a change-detector that had to be
        // edited on every new service, was bumped twice without its name following
        // (it still read "ExactlyEleven" while asserting 14), and never once said
        // anything about behaviour. What matters is that each advertised id is real
        // and advertised once; that it matches what register() binds is pinned by
        // registerBindsAllServicesInContainer.
        $provider = new OrmServiceProvider();
        $provides = $provider->provides();

        self::assertSame(
            array_values(array_unique($provides)),
            $provides,
            'provides() must not advertise the same service twice',
        );

        foreach ($provides as $id) {
            self::assertTrue(
                class_exists($id) || interface_exists($id),
                sprintf('provides() advertises "%s", which is not a class or interface', $id),
            );
        }
    }

    #[Test]
    public function providesReturnsAllStrings(): void
    {
        $provider = new OrmServiceProvider();
        $provides = $provider->provides();

        foreach ($provides as $className) {
            self::assertIsString($className);
        }
    }

    #[Test]
    public function registerBindsAllServicesInContainer(): void
    {
        $provider = new OrmServiceProvider();

        /** @var list<string> $boundIds */
        $boundIds = [];

        $container = $this->createMock(ContainerInterface::class);

        // Satisfy every optional-binding precondition so register() binds the full
        // provides() surface: encryption enabled + crypto present (ColumnEncryptor)
        // and a tenant scope (TenantInsertEnricher). With all capabilities active the
        // bound set equals provides(), in the same order.
        $container->method('has')->willReturnCallback(static fn(string $id): bool => match ($id) {
            'config.orm',
            EncryptorInterface::class,
            KeyProviderInterface::class,
            TenantScopeInterface::class => true,
            default => false,
        });
        $container->method('get')->willReturnCallback(static fn(string $id): mixed => match ($id) {
            'config.orm' => ['encryption' => ['enabled' => true]],
            default => null,
        });
        // Derived from provides(), never hardcoded: a literal count is a second,
        // silent copy of the same contract, and it was the copy that rotted —
        // TenantScopeApplier was bound without being advertised, and this
        // expectation failed on the count rather than naming the missing service.
        $container->expects(self::exactly(count($provider->provides())))
            ->method('bind')
            ->willReturnCallback(function (string $id) use (&$boundIds): void {
                $boundIds[] = $id;
            });

        $provider->register($container);

        self::assertSame($provider->provides(), $boundIds);
    }

    #[Test]
    public function registerSkipsColumnEncryptorWhenEncryptionDisabled(): void
    {
        $provider = new OrmServiceProvider();

        /** @var list<string> $boundIds */
        $boundIds = [];

        // Crypto stack present, but encryption disabled in config: ColumnEncryptor
        // must stay unbound so Container::has(ColumnEncryptor) answers honestly and
        // the hydrator/dehydrator guards fall back to null (the default posture).
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn(string $id): bool => match ($id) {
            EncryptorInterface::class, KeyProviderInterface::class => true,
            default => false,
        });
        $container->method('get')->willReturn(null);
        $container->method('bind')->willReturnCallback(function (string $id) use (&$boundIds): void {
            $boundIds[] = $id;
        });

        $provider->register($container);

        self::assertNotContains(ColumnEncryptorInterface::class, $boundIds);
    }

    #[Test]
    public function registerBindsColumnEncryptorWhenEncryptionEnabledAndCryptoPresent(): void
    {
        $provider = new OrmServiceProvider();

        /** @var list<string> $boundIds */
        $boundIds = [];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn(string $id): bool => match ($id) {
            'config.orm', EncryptorInterface::class, KeyProviderInterface::class => true,
            default => false,
        });
        $container->method('get')->willReturnCallback(static fn(string $id): mixed => match ($id) {
            'config.orm' => ['encryption' => ['enabled' => true]],
            default => null,
        });
        $container->method('bind')->willReturnCallback(function (string $id) use (&$boundIds): void {
            $boundIds[] = $id;
        });

        $provider->register($container);

        self::assertContains(ColumnEncryptorInterface::class, $boundIds);
    }

    #[Test]
    public function registerSkipsTenantEnricherWithoutTenantScope(): void
    {
        $provider = new OrmServiceProvider();

        /** @var list<string> $boundIds */
        $boundIds = [];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->willReturn(null);
        $container->method('bind')->willReturnCallback(function (string $id) use (&$boundIds): void {
            $boundIds[] = $id;
        });

        $provider->register($container);

        self::assertNotContains(TenantInsertEnricher::class, $boundIds);
    }

    #[Test]
    public function registerBindsTenantEnricherWhenTenantScopePresent(): void
    {
        $provider = new OrmServiceProvider();

        /** @var list<string> $boundIds */
        $boundIds = [];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => $id === TenantScopeInterface::class,
        );
        $container->method('get')->willReturn(null);
        $container->method('bind')->willReturnCallback(function (string $id) use (&$boundIds): void {
            $boundIds[] = $id;
        });

        $provider->register($container);

        self::assertContains(TenantInsertEnricher::class, $boundIds);
    }

    #[Test]
    public function registerOrmConfigFactoryLoadsFromContainerWhenAvailable(): void
    {
        $provider = new OrmServiceProvider();

        /** @var array<string, callable> $factories */
        $factories = [];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id, callable $factory) use (&$factories): void {
                $factories[$id] = $factory;
            });

        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => $id === 'config.orm');

        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => match ($id) {
                'config.orm' => [
                    'connection' => 'testing',
                    'tenant_column' => 'org_id',
                ],
                default => null,
            });

        $provider->register($container);

        self::assertArrayHasKey(OrmConfig::class, $factories);

        /** @var OrmConfig $config */
        $config = $factories[OrmConfig::class]();

        self::assertInstanceOf(OrmConfig::class, $config);
        self::assertSame('testing', $config->connection);
        self::assertSame('org_id', $config->tenantColumn);
    }

    #[Test]
    public function registerOrmConfigFactoryUsesDefaultsWhenNoConfigAvailable(): void
    {
        $provider = new OrmServiceProvider();

        /** @var array<string, callable> $factories */
        $factories = [];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id, callable $factory) use (&$factories): void {
                $factories[$id] = $factory;
            });

        $container->method('has')
            ->willReturn(false);

        $provider->register($container);

        self::assertArrayHasKey(OrmConfig::class, $factories);

        /** @var OrmConfig $config */
        $config = $factories[OrmConfig::class]();

        self::assertInstanceOf(OrmConfig::class, $config);
        self::assertSame('default', $config->connection);
        self::assertSame('tenant_id', $config->tenantColumn);
        self::assertSame('deleted_at', $config->softDeleteColumn);
    }

    #[Test]
    public function registerMetadataCompilerFactoryResolvesFromConfig(): void
    {
        $provider = new OrmServiceProvider();

        /** @var array<string, callable> $factories */
        $factories = [];

        $config = OrmConfig::fromArray([]);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id, callable $factory) use (&$factories): void {
                $factories[$id] = $factory;
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => match ($id) {
                OrmConfig::class => $config,
                default => null,
            });

        $provider->register($container);

        self::assertArrayHasKey(MetadataCompiler::class, $factories);

        $compiler = $factories[MetadataCompiler::class]();
        self::assertInstanceOf(MetadataCompiler::class, $compiler);
    }

    #[Test]
    public function registerMetadataRegistryFactoryReturnsCachedRegistry(): void
    {
        $provider = new OrmServiceProvider();

        /** @var array<string, callable> $factories */
        $factories = [];

        $config = OrmConfig::fromArray([]);
        $compiler = new MetadataCompiler($config);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id, callable $factory) use (&$factories): void {
                $factories[$id] = $factory;
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => match ($id) {
                MetadataCompiler::class => $compiler,
                default => null,
            });

        $provider->register($container);

        self::assertArrayHasKey(MetadataRegistryInterface::class, $factories);

        $registry = $factories[MetadataRegistryInterface::class]();
        self::assertInstanceOf(CachedMetadataRegistry::class, $registry);
        self::assertInstanceOf(MetadataRegistryInterface::class, $registry);
    }

    #[Test]
    public function registerCachedMetadataRegistryFactoryReturnsUnderlyingInstance(): void
    {
        $provider = new OrmServiceProvider();

        /** @var array<string, callable> $factories */
        $factories = [];

        $config = OrmConfig::fromArray([]);
        $compiler = new MetadataCompiler($config);
        $cachedRegistry = new CachedMetadataRegistry($compiler);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id, callable $factory) use (&$factories): void {
                $factories[$id] = $factory;
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => match ($id) {
                MetadataRegistryInterface::class => $cachedRegistry,
                default => null,
            });

        $provider->register($container);

        self::assertArrayHasKey(CachedMetadataRegistry::class, $factories);

        $result = $factories[CachedMetadataRegistry::class]();
        self::assertSame($cachedRegistry, $result);
    }

    #[Test]
    public function registerDoesNotBindColumnEncryptorWhenEnabledButCryptoDepsAreMissing(): void
    {
        $provider = new OrmServiceProvider();

        /** @var array<string, callable> $factories */
        $factories = [];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id, callable $factory) use (&$factories): void {
                $factories[$id] = $factory;
            });

        // Encryption enabled in config, but the crypto stack is absent.
        $container->method('has')->willReturnCallback(static fn(string $id): bool => $id === 'config.orm');
        $container->method('get')->willReturnCallback(static fn(string $id): mixed => match ($id) {
            'config.orm' => ['encryption' => ['enabled' => true]],
            default => null,
        });

        $provider->register($container);

        // The guard requires enabled AND has(Encryptor) AND has(KeyProvider); with the
        // crypto deps missing the ColumnEncryptor is not bound (rather than bound to a
        // null-returning factory), keeping Container::has() honest.
        self::assertArrayNotHasKey(ColumnEncryptorInterface::class, $factories);
    }

    #[Test]
    public function registerHydratorFactoryResolvesCorrectly(): void
    {
        $provider = new OrmServiceProvider();

        /** @var array<string, callable> $factories */
        $factories = [];

        $config = OrmConfig::fromArray([]);
        $compiler = new MetadataCompiler($config);
        $registry = new CachedMetadataRegistry($compiler);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id, callable $factory) use (&$factories): void {
                $factories[$id] = $factory;
            });

        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                ColumnEncryptorInterface::class => false,
                default => false,
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => match ($id) {
                MetadataRegistryInterface::class => $registry,
                default => null,
            });

        $provider->register($container);

        self::assertArrayHasKey(EntityHydratorInterface::class, $factories);

        $hydrator = $factories[EntityHydratorInterface::class]();
        self::assertInstanceOf(EntityHydratorInterface::class, $hydrator);
    }

    #[Test]
    public function registerDehydratorFactoryResolvesCorrectly(): void
    {
        $provider = new OrmServiceProvider();

        /** @var array<string, callable> $factories */
        $factories = [];

        $config = OrmConfig::fromArray([]);
        $compiler = new MetadataCompiler($config);
        $registry = new CachedMetadataRegistry($compiler);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id, callable $factory) use (&$factories): void {
                $factories[$id] = $factory;
            });

        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                ColumnEncryptorInterface::class => false,
                default => false,
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => match ($id) {
                MetadataRegistryInterface::class => $registry,
                default => null,
            });

        $provider->register($container);

        self::assertArrayHasKey(EntityDehydrator::class, $factories);

        $dehydrator = $factories[EntityDehydrator::class]();
        self::assertInstanceOf(EntityDehydrator::class, $dehydrator);
    }

    #[Test]
    public function registerSchemaBuilderFactoryResolvesCorrectly(): void
    {
        $provider = new OrmServiceProvider();

        /** @var array<string, callable> $factories */
        $factories = [];

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id, callable $factory) use (&$factories): void {
                $factories[$id] = $factory;
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id) => match ($id) {
                ConnectionInterface::class => $connection,
                default => null,
            });

        $provider->register($container);

        self::assertArrayHasKey(SchemaBuilderInterface::class, $factories);

        $schemaBuilder = $factories[SchemaBuilderInterface::class]();
        self::assertInstanceOf(SchemaBuilderInterface::class, $schemaBuilder);
    }

    #[Test]
    public function registerPersisterFactoryResolvesCorrectly(): void
    {
        $provider = new OrmServiceProvider();

        /** @var array<string, callable> $factories */
        $factories = [];

        $config = OrmConfig::fromArray([]);
        $compiler = new MetadataCompiler($config);
        $registry = new CachedMetadataRegistry($compiler);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $dehydrator = new EntityDehydrator($registry, null);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id, callable $factory) use (&$factories): void {
                $factories[$id] = $factory;
            });

        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                AuditLoggerInterface::class => false,
                default => false,
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id) => match ($id) {
                ConnectionInterface::class => $connection,
                MetadataRegistryInterface::class => $registry,
                EntityDehydrator::class => $dehydrator,
                default => null,
            });

        $provider->register($container);

        self::assertArrayHasKey(AuditingPersister::class, $factories);

        $persister = $factories[AuditingPersister::class]();
        self::assertInstanceOf(AuditingPersister::class, $persister);
    }

    #[Test]
    public function registerPersisterFactoryIncludesAuditLoggerWhenAvailable(): void
    {
        $provider = new OrmServiceProvider();

        /** @var array<string, callable> $factories */
        $factories = [];

        $config = OrmConfig::fromArray([]);
        $compiler = new MetadataCompiler($config);
        $registry = new CachedMetadataRegistry($compiler);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('driver')->willReturn(Driver::SQLite);

        $dehydrator = new EntityDehydrator($registry, null);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id, callable $factory) use (&$factories): void {
                $factories[$id] = $factory;
            });

        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                AuditLoggerInterface::class => true,
                default => false,
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id) => match ($id) {
                ConnectionInterface::class => $connection,
                MetadataRegistryInterface::class => $registry,
                EntityDehydrator::class => $dehydrator,
                AuditLoggerInterface::class => $auditLogger,
                default => null,
            });

        $provider->register($container);

        $persister = $factories[AuditingPersister::class]();
        self::assertInstanceOf(AuditingPersister::class, $persister);
    }

    #[Test]
    public function registerHydratorWithEncryptorAvailable(): void
    {
        $provider = new OrmServiceProvider();

        /** @var array<string, callable> $factories */
        $factories = [];

        $config = OrmConfig::fromArray([]);
        $compiler = new MetadataCompiler($config);
        $registry = new CachedMetadataRegistry($compiler);
        $encryptor = $this->createStub(ColumnEncryptorInterface::class);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id, callable $factory) use (&$factories): void {
                $factories[$id] = $factory;
            });

        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                ColumnEncryptorInterface::class => true,
                default => false,
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id) => match ($id) {
                MetadataRegistryInterface::class => $registry,
                ColumnEncryptorInterface::class => $encryptor,
                default => null,
            });

        $provider->register($container);

        $hydrator = $factories[EntityHydratorInterface::class]();
        self::assertInstanceOf(EntityHydratorInterface::class, $hydrator);
    }

    #[Test]
    public function registerDehydratorWithEncryptorAvailable(): void
    {
        $provider = new OrmServiceProvider();

        /** @var array<string, callable> $factories */
        $factories = [];

        $config = OrmConfig::fromArray([]);
        $compiler = new MetadataCompiler($config);
        $registry = new CachedMetadataRegistry($compiler);
        $encryptor = $this->createStub(ColumnEncryptorInterface::class);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('bind')
            ->willReturnCallback(function (string $id, callable $factory) use (&$factories): void {
                $factories[$id] = $factory;
            });

        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                ColumnEncryptorInterface::class => true,
                default => false,
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id) => match ($id) {
                MetadataRegistryInterface::class => $registry,
                ColumnEncryptorInterface::class => $encryptor,
                default => null,
            });

        $provider->register($container);

        $dehydrator = $factories[EntityDehydrator::class]();
        self::assertInstanceOf(EntityDehydrator::class, $dehydrator);
    }
}
