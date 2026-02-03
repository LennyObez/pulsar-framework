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

        $container->get('unbound');
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

        $container->get('service');

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

        $container->get('a');
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

        $container->get('service');
    }

    #[Test]
    public function factoryReturningNonObjectThrowsException(): void
    {
        $container = new Container();
        $container->bind('service', fn() => 'not an object');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('must return an object');

        $container->get('service');
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
        $container->get('b');

        $instances = $container->getInstances();

        self::assertContains('a', $instances);
        self::assertContains('b', $instances);
    }
}

// Test stubs
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
        public readonly ?DependencyStub $dependency = null,
    ) {}
}
