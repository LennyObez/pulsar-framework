<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Compiler\Pass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Compiler\ContainerBuilder;
use Pulsar\Container\Compiler\Pass\ValidateLifetimesPass;
use Pulsar\Container\Lifetime;
use Pulsar\Container\Scope\ScopeWideningException;
use Pulsar\Container\ServiceDefinition;

#[CoversClass(ValidateLifetimesPass::class)]
#[CoversClass(ScopeWideningException::class)]
final class ValidateLifetimesPassTest extends TestCase
{
    #[Test]
    public function detectsScopeWidening(): void
    {
        $builder = new ContainerBuilder();

        // Request-scoped dependency
        $builder->setDefinition(
            RequestScopedDep::class,
            new ServiceDefinition(id: RequestScopedDep::class, concrete: RequestScopedDep::class, lifetime: Lifetime::RequestScope),
        );

        // Singleton depends on request-scoped
        $builder->setDefinition(
            SingletonWithRequestDep::class,
            new ServiceDefinition(id: SingletonWithRequestDep::class, concrete: SingletonWithRequestDep::class, lifetime: Lifetime::Singleton),
        );

        $pass = new ValidateLifetimesPass();

        $this->expectException(ScopeWideningException::class);
        $this->expectExceptionMessage('Scope widening');

        $pass->process($builder);
    }

    #[Test]
    public function allowsSameScope(): void
    {
        $builder = new ContainerBuilder();

        $builder->setDefinition(
            RequestScopedDep::class,
            new ServiceDefinition(id: RequestScopedDep::class, concrete: RequestScopedDep::class, lifetime: Lifetime::RequestScope),
        );

        $builder->setDefinition(
            RequestScopedService::class,
            new ServiceDefinition(id: RequestScopedService::class, concrete: RequestScopedService::class, lifetime: Lifetime::RequestScope),
        );

        $pass = new ValidateLifetimesPass();
        $pass->process($builder); // Should not throw

        self::assertTrue(true);
    }

    #[Test]
    public function allowsSingletonDependingOnSingleton(): void
    {
        $builder = new ContainerBuilder();

        $builder->setDefinition(
            SingletonDep::class,
            new ServiceDefinition(id: SingletonDep::class, concrete: SingletonDep::class, lifetime: Lifetime::Singleton),
        );

        $builder->setDefinition(
            SingletonWithSingletonDep::class,
            new ServiceDefinition(id: SingletonWithSingletonDep::class, concrete: SingletonWithSingletonDep::class, lifetime: Lifetime::Singleton),
        );

        $pass = new ValidateLifetimesPass();
        $pass->process($builder);

        self::assertTrue(true);
    }
}

class RequestScopedDep {}

class SingletonWithRequestDep
{
    public function __construct(
        public readonly RequestScopedDep $dep,
    ) {}
}

class RequestScopedService
{
    public function __construct(
        public readonly RequestScopedDep $dep,
    ) {}
}

class SingletonDep {}

class SingletonWithSingletonDep
{
    public function __construct(
        public readonly SingletonDep $dep,
    ) {}
}
