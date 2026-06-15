<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Core\Controller\ReflectionControllerResolver;
use Pulsar\Routing\RoutingException;

#[CoversClass(ReflectionControllerResolver::class)]
final class ReflectionControllerResolverTest extends TestCase
{
    private function resolver(?Container $container = null): ReflectionControllerResolver
    {
        return new ReflectionControllerResolver($container ?? new Container());
    }

    #[Test]
    public function resolvesControllerWithNoDependencies(): void
    {
        $controller = $this->resolver()->resolve(NoDepController::class);

        self::assertInstanceOf(NoDepController::class, $controller);
    }

    #[Test]
    public function returnsContainerBoundInstance(): void
    {
        $container = new Container();
        $bound = new NoDepController();
        $container->instance(NoDepController::class, $bound);

        self::assertSame($bound, $this->resolver($container)->resolve(NoDepController::class));
    }

    #[Test]
    public function autowiresDependencyFromContainer(): void
    {
        $container = new Container();
        $dependency = new DependencyService();
        $container->instance(DependencyService::class, $dependency);

        $controller = $this->resolver($container)->resolve(NeedsDependencyController::class);

        self::assertInstanceOf(NeedsDependencyController::class, $controller);
        self::assertSame($dependency, $controller->dependency);
    }

    #[Test]
    public function usesDefaultValueWhenDependencyUnresolvable(): void
    {
        $controller = $this->resolver()->resolve(DefaultedParamController::class);

        self::assertInstanceOf(DefaultedParamController::class, $controller);
        self::assertSame('fallback', $controller->value);
    }

    #[Test]
    public function usesNullWhenNullableDependencyUnresolvable(): void
    {
        $controller = $this->resolver()->resolve(NullableDependencyController::class);

        self::assertInstanceOf(NullableDependencyController::class, $controller);
        self::assertNull($controller->dependency);
    }

    #[Test]
    public function throwsForUnresolvableRequiredParameter(): void
    {
        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('cannot resolve constructor parameter');

        $this->resolver()->resolve(NeedsDependencyController::class);
    }

    #[Test]
    public function throwsForNonExistentClass(): void
    {
        $this->expectException(RoutingException::class);

        /** @phpstan-ignore-next-line argument.type intentional invalid class for the test */
        $this->resolver()->resolve('Pulsar\\Tests\\DoesNotExist');
    }

    #[Test]
    public function throwsForNonInstantiableType(): void
    {
        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('not instantiable');

        $this->resolver()->resolve(AbstractController::class);
    }
}

final class NoDepController
{
    public function handle(): string
    {
        return 'ok';
    }
}

final class DependencyService {}

final class NeedsDependencyController
{
    public function __construct(public readonly DependencyService $dependency) {}
}

final class DefaultedParamController
{
    public function __construct(public readonly string $value = 'fallback') {}
}

final class NullableDependencyController
{
    public function __construct(public readonly ?DependencyService $dependency) {}
}

abstract class AbstractController {}
