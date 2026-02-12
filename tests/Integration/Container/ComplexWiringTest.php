<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Container;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\BindingType;
use Pulsar\Container\Container;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;

#[CoversClass(Container::class)]
final class ComplexWiringTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    #[Test]
    public function singletonBindingReturnsSameInstance(): void
    {
        $this->container->bind(WireServiceA::class, WireServiceA::class, BindingType::Singleton);

        $a1 = $this->container->get(WireServiceA::class);
        $a2 = $this->container->get(WireServiceA::class);

        self::assertSame($a1, $a2);
    }

    #[Test]
    public function factoryBindingReturnsNewInstanceEachTime(): void
    {
        $this->container->bind(WireServiceA::class, WireServiceA::class, BindingType::Factory);

        $a1 = $this->container->get(WireServiceA::class);
        $a2 = $this->container->get(WireServiceA::class);

        self::assertNotSame($a1, $a2);
    }

    #[Test]
    public function autowiresServiceWithThreeDependencies(): void
    {
        $this->container->bind(WireServiceA::class, WireServiceA::class);
        $this->container->bind(WireServiceB::class, WireServiceB::class);
        $this->container->bind(WireComposite::class, WireComposite::class);

        /** @var WireComposite $composite */
        $composite = $this->container->get(WireComposite::class);

        self::assertInstanceOf(WireComposite::class, $composite);
        self::assertSame('A+B', $composite->combined());
    }

    #[Test]
    public function interfaceBindingResolvesToConcrete(): void
    {
        $this->container->bind(WireInterface::class, WireConcreteImpl::class);

        $svc = $this->container->get(WireInterface::class);

        self::assertInstanceOf(WireConcreteImpl::class, $svc);
        self::assertSame('concrete', $svc->value());
    }

    #[Test]
    public function callbackBindingIsUsedForCustomConstruction(): void
    {
        $this->container->bind(WireServiceA::class, fn() => new WireServiceA());

        $svc = $this->container->get(WireServiceA::class);

        self::assertInstanceOf(WireServiceA::class, $svc);
    }

    #[Test]
    public function instanceRegistrationShortCircuitsBinding(): void
    {
        $existing = new WireServiceA();
        $this->container->instance(WireServiceA::class, $existing);

        $resolved = $this->container->get(WireServiceA::class);

        self::assertSame($existing, $resolved);
    }

    #[Test]
    public function forgetInstanceAllowsRebind(): void
    {
        $this->container->bind(WireServiceA::class, WireServiceA::class, BindingType::Singleton);

        $first = $this->container->get(WireServiceA::class);
        $this->container->forgetInstance(WireServiceA::class);

        $second = $this->container->get(WireServiceA::class);

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function circularDependencyThrowsContainerException(): void
    {
        $this->container->bind(WireCircularA::class, WireCircularA::class);
        $this->container->bind(WireCircularB::class, WireCircularB::class);

        $this->expectException(ContainerException::class);
        (void) $this->container->get(WireCircularA::class);
    }

    #[Test]
    public function resolutionHintsAccelerateResolution(): void
    {
        $this->container->bind(WireServiceA::class, WireServiceA::class);
        $this->container->bind(WireDepOnA::class, WireDepOnA::class);

        $this->container->setResolutionHints([
            WireDepOnA::class => [
                ['name' => 'serviceA', 'type' => WireServiceA::class],
            ],
        ]);

        /** @var WireDepOnA $dep */
        $dep = $this->container->get(WireDepOnA::class);

        self::assertInstanceOf(WireDepOnA::class, $dep);
        self::assertSame('A', $dep->name());
    }

    #[Test]
    public function getThrowsNotFoundForUnboundId(): void
    {
        $this->expectException(NotFoundException::class);
        (void) $this->container->get('Unbound\Service');
    }

    #[Test]
    public function hasReturnsTrueForBoundId(): void
    {
        $this->container->bind(WireServiceA::class, WireServiceA::class);

        self::assertTrue($this->container->has(WireServiceA::class));
        self::assertFalse($this->container->has('Unbound\Service'));
    }

    #[Test]
    public function clearResolutionHintsViaNull(): void
    {
        $this->container->bind(WireServiceA::class, WireServiceA::class);
        $this->container->setResolutionHints([WireServiceA::class => []]);
        $this->container->setResolutionHints(null);

        self::assertSame([], $this->container->resolutionHints);
    }
}

/** @internal */
final class WireServiceA
{
    public function name(): string
    {
        return 'A';
    }
}

/** @internal */
final class WireServiceB
{
    public function name(): string
    {
        return 'B';
    }
}

/** @internal */
final class WireComposite
{
    public function __construct(
        private readonly WireServiceA $a,
        private readonly WireServiceB $b,
    ) {}

    public function combined(): string
    {
        return $this->a->name() . '+' . $this->b->name();
    }
}

/** @internal */
interface WireInterface
{
    public function value(): string;
}

/** @internal */
final class WireConcreteImpl implements WireInterface
{
    public function value(): string
    {
        return 'concrete';
    }
}

/** @internal */
final class WireDepOnA
{
    public function __construct(private readonly WireServiceA $serviceA) {}

    public function name(): string
    {
        return $this->serviceA->name();
    }
}

/** @internal */
final class WireCircularA
{
    public function __construct(public readonly WireCircularB $b) {}
}

/** @internal */
final class WireCircularB
{
    public function __construct(public readonly WireCircularA $a) {}
}
