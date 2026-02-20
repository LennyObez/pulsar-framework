<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Internal\BoundaryGuard;

#[CoversClass(BoundaryGuard::class)]
final class BoundaryGuardTest extends TestCase
{
    #[Test]
    public function it_delegates_has_to_inner_container(): void
    {
        $inner = $this->createStub(ContainerInterface::class);
        $inner->method('has')->willReturn(true);
        $logger = $this->createStub(LoggerInterface::class);

        $guard = new BoundaryGuard($inner, $logger);

        self::assertTrue($guard->has('SomeService'));
    }

    #[Test]
    public function it_delegates_get_to_inner_container(): void
    {
        $service = new \stdClass();
        $inner = $this->createStub(ContainerInterface::class);
        $inner->method('get')->willReturn($service);
        $logger = $this->createStub(LoggerInterface::class);

        $guard = new BoundaryGuard($inner, $logger);

        self::assertSame($service, $guard->get('SomeService'));
    }

    #[Test]
    public function it_delegates_bind_to_inner_container(): void
    {
        $inner = $this->createMock(ContainerInterface::class);
        $inner->expects(self::once())->method('bind')->with('id', 'ConcreteClass');
        $logger = $this->createStub(LoggerInterface::class);

        $guard = new BoundaryGuard($inner, $logger);
        $guard->bind('id', 'ConcreteClass');
    }

    #[Test]
    public function it_delegates_instance_to_inner_container(): void
    {
        $instance = new \stdClass();
        $inner = $this->createMock(ContainerInterface::class);
        $inner->expects(self::once())->method('instance')->with('id', $instance);
        $logger = $this->createStub(LoggerInterface::class);

        $guard = new BoundaryGuard($inner, $logger);
        $guard->instance('id', $instance);
    }

    #[Test]
    public function it_delegates_forget_instance_to_inner_container(): void
    {
        $inner = $this->createMock(ContainerInterface::class);
        $inner->expects(self::once())->method('forgetInstance')->with('id');
        $logger = $this->createStub(LoggerInterface::class);

        $guard = new BoundaryGuard($inner, $logger);
        $guard->forgetInstance('id');
    }

    #[Test]
    public function it_delegates_get_bindings_to_inner_container(): void
    {
        $inner = $this->createStub(ContainerInterface::class);
        $inner->method('getBindings')->willReturn(['a', 'b']);
        $logger = $this->createStub(LoggerInterface::class);

        $guard = new BoundaryGuard($inner, $logger);
        self::assertSame(['a', 'b'], $guard->getBindings());
    }

    #[Test]
    public function it_delegates_get_instances_to_inner_container(): void
    {
        $inner = $this->createStub(ContainerInterface::class);
        $inner->method('getInstances')->willReturn(['x']);
        $logger = $this->createStub(LoggerInterface::class);

        $guard = new BoundaryGuard($inner, $logger);
        self::assertSame(['x'], $guard->getInstances());
    }

    #[Test]
    public function it_delegates_set_resolution_hints_to_inner_container(): void
    {
        $inner = $this->createMock(ContainerInterface::class);
        $inner->expects(self::once())->method('setResolutionHints')->with(null);
        $logger = $this->createStub(LoggerInterface::class);

        $guard = new BoundaryGuard($inner, $logger);
        $guard->setResolutionHints(null);
    }

    #[Test]
    public function it_allows_non_pulsar_namespace_services_without_checks(): void
    {
        $service = new \stdClass();
        $inner = $this->createStub(ContainerInterface::class);
        $inner->method('get')->willReturn($service);
        $logger = $this->createStub(LoggerInterface::class);

        $guard = new BoundaryGuard($inner, $logger);

        // Non-Pulsar services bypass boundary checks entirely
        self::assertSame($service, $guard->get('Vendor\\SomePackage\\Service'));
    }

    #[Test]
    public function throw_on_violation_mode_works(): void
    {
        $inner = $this->createStub(ContainerInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $guard = new BoundaryGuard($inner, $logger, throwOnViolation: true);

        // Since we're calling from a test class (not Pulsar namespace),
        // no violation is triggered — this verifies the guard doesn't crash
        $inner->method('get')->willReturn(new \stdClass());
        self::assertInstanceOf(\stdClass::class, $guard->get('Pulsar\\Auth\\Internal\\TokenHasher'));
    }
}
