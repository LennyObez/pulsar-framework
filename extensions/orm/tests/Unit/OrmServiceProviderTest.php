<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;
use Pulsar\Extension\Orm\Contracts\EntityHydratorInterface;
use Pulsar\Extension\Orm\Contracts\TenantScopeInterface;
use Pulsar\Extension\Orm\Features\Hydration\EntityDehydrator;
use Pulsar\Extension\Orm\Features\Tenancy\TenantInsertEnricher;
use Pulsar\Extension\Orm\Gateway\EntityManager;
use Pulsar\Extension\Orm\OrmServiceProvider;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\KeyProviderInterface;

/**
 * Locks in the OrmServiceProvider optional-binding contract.
 *
 * ColumnEncryptorInterface and TenantInsertEnricher must be registered
 * conditionally, not as always-on factory closures. The container rejects a
 * factory that does not return an object, so a closure yielding null in the
 * default configuration (encryption disabled, single-tenant) would make
 * EntityManager unresolvable out of the box with "Factory must return an object".
 */
final class OrmServiceProviderTest extends TestCase
{
    private function containerWithConnection(): Container
    {
        $container = new Container();
        $container->instance(ConnectionInterface::class, $this->createStub(ConnectionInterface::class));

        return $container;
    }

    #[Test]
    public function entityManagerResolvesUnderDefaultConfig(): void
    {
        // Default: no config.orm (encryption disabled), no crypto stack, no tenancy.
        // EntityManager must still resolve in this bare configuration.
        $container = $this->containerWithConnection();
        new OrmServiceProvider()->register($container);

        self::assertFalse(
            $container->has(ColumnEncryptorInterface::class),
            'The column encryptor must not be bound when encryption is disabled.',
        );
        self::assertFalse(
            $container->has(TenantInsertEnricher::class),
            'The tenant enricher must not be bound when no tenant scope is available.',
        );

        self::assertInstanceOf(EntityManager::class, $container->get(EntityManager::class));
        self::assertInstanceOf(EntityHydratorInterface::class, $container->get(EntityHydratorInterface::class));
        self::assertInstanceOf(EntityDehydrator::class, $container->get(EntityDehydrator::class));
    }

    #[Test]
    public function columnEncryptorStaysUnboundWhenEncryptionDisabledEvenWithCryptoPresent(): void
    {
        // The crypto stack is present but encryption is disabled (the default), so the
        // encryptor must remain unbound: the enabled flag gates the binding, not just
        // the presence of crypto services. EntityManager must still resolve.
        $container = $this->containerWithConnection();
        $container->instance(EncryptorInterface::class, $this->createStub(EncryptorInterface::class));
        $container->instance(KeyProviderInterface::class, $this->createStub(KeyProviderInterface::class));

        new OrmServiceProvider()->register($container);

        self::assertFalse($container->has(ColumnEncryptorInterface::class));
        self::assertInstanceOf(EntityManager::class, $container->get(EntityManager::class));
    }

    #[Test]
    public function tenantEnricherIsBoundWhenTenantScopeIsAvailable(): void
    {
        // A tenant scope bound before the provider registers must enable the enricher.
        $container = $this->containerWithConnection();
        $container->instance(TenantScopeInterface::class, $this->createStub(TenantScopeInterface::class));

        new OrmServiceProvider()->register($container);

        self::assertTrue(
            $container->has(TenantInsertEnricher::class),
            'The tenant enricher must be bound when a tenant scope is available.',
        );
        self::assertInstanceOf(TenantInsertEnricher::class, $container->get(TenantInsertEnricher::class));
        self::assertInstanceOf(EntityManager::class, $container->get(EntityManager::class));
    }
}
