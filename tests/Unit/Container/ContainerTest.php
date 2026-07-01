<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\BindingType;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use Pulsar\Container\Provider\DeferredProviderRegistry;
use Pulsar\Container\Provider\DeferredServiceProviderInterface;
use stdClass;

#[CoversClass(Container::class)]
#[CoversClass(BindingType::class)]
#[CoversClass(ContainerException::class)]
#[CoversClass(NotFoundException::class)]
final class ContainerTest extends TestCase
{
    #[Test]
    public function containerImplementsPsrInterface(): void
    {
        $container = new Container();

        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertInstanceOf(\Psr\Container\ContainerInterface::class, $container);
    }

    #[Test]
    public function hasReturnsFalseForUnboundId(): void
    {
        $container = new Container();

        self::assertFalse($container->has('unbound'));
    }

    #[Test]
    public function hasReturnsTrueForBoundId(): void
    {
        $container = new Container();
        $container->bind('service', fn() => new stdClass());

        self::assertTrue($container->has('service'));
    }

    #[Test]
    public function hasReturnsTrueForInstance(): void
    {
        $container = new Container();
        $container->instance('service', new stdClass());

        self::assertTrue($container->has('service'));
    }

    #[Test]
    public function getThrowsNotFoundExceptionForUnboundId(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('No binding found for "unbound"');

        $_ = $container->get('unbound');
    }

    #[Test]
    public function getReturnsInstanceForBoundId(): void
    {
        $container = new Container();
        $container->instance('service', $instance = new stdClass());

        self::assertSame($instance, $container->get('service'));
    }

    #[Test]
    public function getResolvesSingletonOnce(): void
    {
        $container = new Container();
        $callCount = 0;

        $container->bind('service', function () use (&$callCount) {
            $callCount++;
            return new stdClass();
        }, BindingType::Singleton);

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertSame($first, $second);
        self::assertSame(1, $callCount);
    }

    #[Test]
    public function singletonRegistersAsSingleton(): void
    {
        $container = new Container();
        $callCount = 0;

        $container->singleton('service', function () use (&$callCount) {
            $callCount++;
            return new stdClass();
        });

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertSame($first, $second);
        self::assertSame(1, $callCount);
    }

    #[Test]
    public function singletonOverwritesPreviousBinding(): void
    {
        $container = new Container();

        $container->singleton('service', fn() => (object) ['v' => 1]);
        /** @var stdClass $first */
        $first = $container->get('service');

        $container->singleton('service', fn() => (object) ['v' => 2]);
        /** @var stdClass $second */
        $second = $container->get('service');

        self::assertSame(1, $first->v);
        self::assertSame(2, $second->v);
        self::assertNotSame($first, $second);
    }

    #[Test]
    public function getResolvesFactoryEachTime(): void
    {
        $container = new Container();
        $callCount = 0;

        $container->bind('service', function () use (&$callCount) {
            $callCount++;
            return new stdClass();
        }, BindingType::Factory);

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertNotSame($first, $second);
        self::assertSame(2, $callCount);
    }

    #[Test]
    public function factoryReceivesContainer(): void
    {
        $container = new Container();
        $receivedContainer = null;

        $container->bind('service', function (ContainerInterface $c) use (&$receivedContainer) {
            $receivedContainer = $c;
            return new stdClass();
        });

        $_ = $container->get('service');

        self::assertSame($container, $receivedContainer);
    }

    #[Test]
    public function rebindingClearsCachedInstance(): void
    {
        $container = new Container();

        $container->bind('service', fn() => new stdClass());
        $first = $container->get('service');

        $container->bind('service', fn() => new stdClass());
        $second = $container->get('service');

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function autowireBuildsClassWithoutConstructor(): void
    {
        $container = new Container();
        $container->bind(stdClass::class, stdClass::class);

        $instance = $container->get(stdClass::class);

        self::assertInstanceOf(stdClass::class, $instance);
    }

    #[Test]
    public function autowireResolvesTypedDependencies(): void
    {
        $container = new Container();
        $container->bind(DependencyStub::class, DependencyStub::class);
        $container->bind(ServiceStub::class, ServiceStub::class);

        $service = $container->get(ServiceStub::class);

        self::assertInstanceOf(ServiceStub::class, $service);
        self::assertInstanceOf(DependencyStub::class, $service->dependency);
    }

    #[Test]
    public function getAutowiresUnboundInstantiableConcrete(): void
    {
        $container = new Container();

        // Neither ServiceStub nor its concrete dependency is bound — both are
        // autowired on demand so applications need not bind every concrete.
        $service = $container->get(ServiceStub::class);

        self::assertInstanceOf(ServiceStub::class, $service);
        self::assertInstanceOf(DependencyStub::class, $service->dependency);
        // Autowiring does not register a binding: has() still reports false.
        self::assertFalse($container->has(ServiceStub::class));
    }

    #[Test]
    public function getThrowsNotFoundForUnboundInterface(): void
    {
        $container = new Container();

        // An interface is not instantiable and cannot be autowired without a
        // binding — it must still surface as NotFound, not silently succeed.
        $this->expectException(NotFoundException::class);

        $_ = $container->get(ServiceContractStub::class);
    }

    #[Test]
    public function autowireUsesDefaultValuesForOptionalParameters(): void
    {
        $container = new Container();
        $container->bind(OptionalDependencyStub::class, OptionalDependencyStub::class);

        $service = $container->get(OptionalDependencyStub::class);

        self::assertInstanceOf(OptionalDependencyStub::class, $service);
        self::assertSame('default', $service->value);
    }

    #[Test]
    public function autowireResolvesNullableType(): void
    {
        $container = new Container();
        $container->bind(NullableDependencyStub::class, NullableDependencyStub::class);

        $service = $container->get(NullableDependencyStub::class);

        self::assertInstanceOf(NullableDependencyStub::class, $service);
        self::assertNull($service->dependency);
    }

    #[Test]
    public function circularDependencyThrowsException(): void
    {
        $container = new Container();

        $container->bind('a', fn(ContainerInterface $c) => $c->get('b'));
        $container->bind('b', fn(ContainerInterface $c) => $c->get('a'));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');

        $_ = $container->get('a');
    }

    #[Test]
    public function nonExistentClassThrowsException(): void
    {
        $container = new Container();
        /** @var class-string $nonExistent */
        $nonExistent = trim('NonExistentClass');
        $container->bind('service', $nonExistent);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('does not exist');

        $_ = $container->get('service');
    }

    #[Test]
    public function factoryReturningNonObjectThrowsException(): void
    {
        $container = new Container();
        $container->bind('service', fn() => 'not an object');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('must return an object');

        $_ = $container->get('service');
    }

    #[Test]
    public function getBindingsReturnsAllBindingIds(): void
    {
        $container = new Container();
        $container->bind('a', fn() => new stdClass());
        $container->bind('b', fn() => new stdClass());

        $bindings = $container->getBindings();

        self::assertContains('a', $bindings);
        self::assertContains('b', $bindings);
    }

    #[Test]
    public function getInstancesReturnsCachedInstanceIds(): void
    {
        $container = new Container();
        $container->instance('a', new stdClass());
        $container->bind('b', fn() => new stdClass());
        $_ = $container->get('b');

        $instances = $container->getInstances();

        self::assertContains('a', $instances);
        self::assertContains('b', $instances);
    }

    #[Test]
    public function forgetInstanceRemovesCachedInstance(): void
    {
        $container = new Container();
        $container->instance('service', new stdClass());

        self::assertTrue($container->has('service'));

        $container->forgetInstance('service');

        self::assertFalse($container->has('service'));
    }

    #[Test]
    public function setResolutionHintsLoadsHints(): void
    {
        $container = new Container();
        $container->setResolutionHints([
            ServiceStub::class => [
                ['name' => 'dependency', 'type' => DependencyStub::class],
            ],
        ]);

        self::assertNotEmpty($container->resolutionHints);
    }

    #[Test]
    public function setResolutionHintsNullClearsHints(): void
    {
        $container = new Container();
        $container->setResolutionHints([
            ServiceStub::class => [
                ['name' => 'dependency', 'type' => DependencyStub::class],
            ],
        ]);

        $container->setResolutionHints(null);

        self::assertEmpty($container->resolutionHints);
    }

    #[Test]
    public function buildFromHintsResolvesWithCachedHints(): void
    {
        $container = new Container();
        $container->bind(DependencyStub::class, DependencyStub::class);
        $container->bind(ServiceStub::class, ServiceStub::class);

        $container->setResolutionHints([
            ServiceStub::class => [
                ['name' => 'dependency', 'type' => DependencyStub::class],
            ],
        ]);

        $service = $container->get(ServiceStub::class);

        self::assertInstanceOf(ServiceStub::class, $service);
        self::assertInstanceOf(DependencyStub::class, $service->dependency);
    }

    #[Test]
    public function buildFallsBackToReflectionWhenHintsFail(): void
    {
        $container = new Container();
        $container->bind(DependencyStub::class, DependencyStub::class);
        $container->bind(ServiceStub::class, ServiceStub::class);

        // Set invalid hints — type doesn't exist in container
        /** @var class-string $bogus */
        $bogus = trim('NonExistentClass');
        $container->setResolutionHints([
            ServiceStub::class => [
                ['name' => 'dependency', 'type' => $bogus],
            ],
        ]);

        // Should still resolve via reflection fallback
        $service = $container->get(ServiceStub::class);

        self::assertInstanceOf(ServiceStub::class, $service);
    }

    #[Test]
    public function untypedParameterWithoutDefaultThrows(): void
    {
        $container = new Container();
        $container->bind(UntypedParamStub::class, UntypedParamStub::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('no type hint');

        $_ = $container->get(UntypedParamStub::class);
    }

    #[Test]
    public function builtinTypeParameterWithoutDefaultThrows(): void
    {
        $container = new Container();
        $container->bind(BuiltinTypeStub::class, BuiltinTypeStub::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('non-class type');

        $_ = $container->get(BuiltinTypeStub::class);
    }

    #[Test]
    public function uninstantiableClassThrows(): void
    {
        $container = new Container();
        $container->bind(AbstractStub::class, AbstractStub::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('not instantiable');

        $_ = $container->get(AbstractStub::class);
    }

    #[Test]
    public function hasReturnsTrueForDeferredProviderService(): void
    {
        $container = new Container();
        $registry = new DeferredProviderRegistry();

        $provider = new class implements DeferredServiceProviderInterface {
            public function register(ContainerInterface $container): void
            {
                $container->instance('deferred.service', new stdClass());
            }

            public function provides(): array
            {
                return ['deferred.service'];
            }

            public function isDeferred(): bool
            {
                return true;
            }
        };

        $registry->register($provider);
        $container->setDeferredProviderRegistry($registry);

        self::assertTrue($container->has('deferred.service'));
    }
}

// Test stubs
interface ServiceContractStub {}

class DependencyStub {}

class ServiceStub
{
    public function __construct(
        public readonly DependencyStub $dependency,
    ) {}
}

class OptionalDependencyStub
{
    public function __construct(
        public readonly string $value = 'default',
    ) {}
}

class NullableDependencyStub
{
    public function __construct(
        // Interface: genuinely unresolvable without a binding, so a nullable
        // parameter falls back to null (a nullable concrete would be autowired).
        public readonly ?ServiceContractStub $dependency = null,
    ) {}
}

class UntypedParamStub
{
    /** @var mixed */
    public $value;

    /** @param mixed $value */
    public function __construct($value)
    {
        $this->value = $value;
    }
}

class BuiltinTypeStub
{
    public function __construct(
        public readonly int $count,
    ) {}
}

abstract class AbstractStub {}
