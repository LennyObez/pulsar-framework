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
    public function autowiresUnboundConcreteDependency(): void
    {
        // The concrete DependencyService is NOT bound, yet a controller that
        // depends on it resolves — the container autowires the plain concrete.
        $controller = $this->resolver()->resolve(NeedsDependencyController::class);

        self::assertInstanceOf(NeedsDependencyController::class, $controller);
        self::assertInstanceOf(DependencyService::class, $controller->dependency);
    }

    #[Test]
    public function usesNullWhenNullableDependencyUnresolvable(): void
    {
        // An unbound interface cannot be autowired; the nullable parameter
        // falls back to null rather than failing.
        $controller = $this->resolver()->resolve(NullableInterfaceController::class);

        self::assertInstanceOf(NullableInterfaceController::class, $controller);
        self::assertNull($controller->dependency);
    }

    #[Test]
    public function throwsNamingControllerAndParameterForUnresolvableRequiredInterface(): void
    {
        $this->expectException(RoutingException::class);
        // The error names the controller, the unresolved dependency and the
        // parameter — not an opaque "no binding found".
        $this->expectExceptionMessageIsOrContains(NeedsInterfaceController::class);
        $this->expectExceptionMessageIsOrContains(ServiceInterface::class);

        $this->resolver()->resolve(NeedsInterfaceController::class);
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
        $this->expectExceptionMessageIsOrContains('not instantiable');

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

interface ServiceInterface {}

final class NeedsInterfaceController
{
    public function __construct(public readonly ServiceInterface $dependency) {}
}

final class NullableInterfaceController
{
    public function __construct(public readonly ?ServiceInterface $dependency) {}
}

abstract class AbstractController {}
