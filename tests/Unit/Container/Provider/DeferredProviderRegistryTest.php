<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Provider\DeferredProviderRegistry;
use Pulsar\Container\Provider\DeferredServiceProviderInterface;
use stdClass;

#[CoversClass(DeferredProviderRegistry::class)]
final class DeferredProviderRegistryTest extends TestCase
{
    #[Test]
    public function hasReturnsFalseForUnregistered(): void
    {
        $registry = new DeferredProviderRegistry();

        self::assertFalse($registry->has('unregistered'));
    }

    #[Test]
    public function hasReturnsTrueForRegistered(): void
    {
        $registry = new DeferredProviderRegistry();
        $provider = $this->createProvider(['service.a']);

        $registry->register($provider);

        self::assertTrue($registry->has('service.a'));
    }

    #[Test]
    public function resolveTriggersProviderRegistration(): void
    {
        $registry = new DeferredProviderRegistry();
        $container = new Container();
        $registered = false;

        $provider = new class ($registered) implements DeferredServiceProviderInterface {
            public function __construct(private bool &$registered) {}

            public function register(ContainerInterface $container): void
            {
                $this->registered = true;
                $container->instance('service.a', new stdClass());
            }

            public function provides(): array
            {
                return ['service.a'];
            }

            public function isDeferred(): bool
            {
                return true;
            }
        };

        $registry->register($provider);
        $registry->resolve('service.a', $container);

        self::assertTrue($registered);
        self::assertTrue($container->has('service.a'));
    }

    #[Test]
    public function providerRegisteredOnlyOnce(): void
    {
        $registry = new DeferredProviderRegistry();
        $container = new Container();
        $callCount = 0;

        $provider = new class ($callCount) implements DeferredServiceProviderInterface {
            public function __construct(private int &$count) {}

            public function register(ContainerInterface $container): void
            {
                $this->count++;
            }

            public function provides(): array
            {
                return ['a', 'b'];
            }

            public function isDeferred(): bool
            {
                return true;
            }
        };

        $registry->register($provider);
        $registry->resolve('a', $container);
        $registry->resolve('b', $container);

        self::assertSame(1, $callCount);
    }

    #[Test]
    public function registerThrowsOnConflictingProvider(): void
    {
        $registry = new DeferredProviderRegistry();
        $providerA = new ConflictProviderA();
        $providerB = new ConflictProviderB();

        $registry->register($providerA);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('already claimed');

        $registry->register($providerB);
    }

    #[Test]
    public function registerAllowsSameProviderClass(): void
    {
        $registry = new DeferredProviderRegistry();
        $provider = $this->createProvider(['service.a']);

        // Re-registering the same provider class is allowed (idempotent)
        $registry->register($provider);
        $registry->register($provider);

        self::assertTrue($registry->has('service.a'));
    }

    /**
     * @param list<string> $provides
     */
    private function createProvider(array $provides): DeferredServiceProviderInterface
    {
        return new class ($provides) implements DeferredServiceProviderInterface {
            /** @param list<string> $ids */
            public function __construct(private readonly array $ids) {}

            public function register(ContainerInterface $container): void {}

            public function provides(): array
            {
                return $this->ids;
            }

            public function isDeferred(): bool
            {
                return true;
            }
        };
    }
}

// Named providers for conflict detection tests (distinct class names required)

final class ConflictProviderA implements DeferredServiceProviderInterface
{
    public function register(ContainerInterface $container): void {}

    public function provides(): array
    {
        return ['service.shared'];
    }

    public function isDeferred(): bool
    {
        return true;
    }
}

final class ConflictProviderB implements DeferredServiceProviderInterface
{
    public function register(ContainerInterface $container): void {}

    public function provides(): array
    {
        return ['service.shared'];
    }

    public function isDeferred(): bool
    {
        return true;
    }
}
