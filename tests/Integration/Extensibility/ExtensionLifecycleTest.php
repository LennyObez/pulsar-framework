<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extensibility;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Kernel;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionLifecycle;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Routing\RouterInterface;

#[CoversClass(Kernel::class)]
#[CoversClass(ExtensionBootstrap::class)]
final class ExtensionLifecycleTest extends TestCase
{
    #[Test]
    public function extensionCanRegisterServicesAndRoutes(): void
    {
        // Arrange: Create extension that registers a service and route
        $bootstrap = ExtensionBootstrap::create();
        $extension = new TestableExtension();
        $manifest = ExtensionManifest::fromArray([
            'name' => 'test/extension',
            'version' => '1.0.0', 'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'extension_class' => TestableExtension::class,
            'provides' => [
                'services' => [TestableService::class],
                'routes' => true,
            ],
        ]);

        $bootstrap->addExtension($extension, $manifest);

        // Act: Create kernel with extension bootstrap and boot
        $kernel = new Kernel(extensionBootstrap: $bootstrap);
        $kernel->boot();

        // Assert: Service is registered
        self::assertTrue($kernel->container()->has(TestableService::class));
        $service = $kernel->container()->get(TestableService::class);
        self::assertInstanceOf(TestableService::class, $service);

        // Assert: Route is registered
        $routes = $kernel->router()->routes();
        self::assertNotEmpty($routes);
        self::assertSame('/test', $routes[0]->path);

        // Assert: Extension is in booted state
        self::assertSame(
            ExtensionLifecycle::Booted,
            $bootstrap->registry->getState('test/extension'),
        );
    }

    #[Test]
    public function kernelCanHandleRequestWithExtensionRoute(): void
    {
        // Arrange: Create extension with route
        $bootstrap = ExtensionBootstrap::create();
        $extension = new TestableExtension();
        $manifest = ExtensionManifest::fromArray([
            'name' => 'test/extension',
            'version' => '1.0.0', 'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'extension_class' => TestableExtension::class,
        ]);

        $bootstrap->addExtension($extension, $manifest);

        $kernel = new Kernel(extensionBootstrap: $bootstrap);
        $kernel->boot();

        // Act: Handle request to extension route
        $request = new ServerRequest(
            method: 'GET',
            uri: '/test',
        );

        $response = $kernel->handle($request);

        // Assert: Response is from extension controller
        self::assertSame(200, $response->getStatusCode());
        /** @var array{message: string} $data */
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('Hello from extension!', $data['message']);
    }

    #[Test]
    public function serviceProviderRegistersServices(): void
    {
        // Arrange: Create extension with service provider
        $bootstrap = ExtensionBootstrap::create();
        $extension = new ExtensionWithProvider();
        $manifest = ExtensionManifest::fromArray([
            'name' => 'test/provider-extension',
            'version' => '1.0.0', 'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'extension_class' => ExtensionWithProvider::class,
        ]);

        $bootstrap->addExtension($extension, $manifest);

        $kernel = new Kernel(extensionBootstrap: $bootstrap);
        $kernel->boot();

        // Assert: Service from provider is registered
        self::assertTrue($kernel->container()->has(ProvidedService::class));
        $service = $kernel->container()->get(ProvidedService::class);
        self::assertSame('Provided by service provider', $service->getValue());
    }

    #[Test]
    public function extensionBootstrapIsAccessibleFromKernel(): void
    {
        $bootstrap = ExtensionBootstrap::create();
        $kernel = new Kernel(extensionBootstrap: $bootstrap);

        self::assertSame($bootstrap, $kernel->extensionBootstrap());
    }

    #[Test]
    public function kernelWithoutExtensionsBootsNormally(): void
    {
        $kernel = new Kernel();
        $kernel->boot();

        self::assertTrue($kernel->booted);
        self::assertNull($kernel->extensionBootstrap());
    }

    #[Test]
    public function multipleExtensionsBootInDependencyOrder(): void
    {
        /** @var ArrayObject<int, string> $bootOrder */
        $bootOrder = new ArrayObject();

        $bootstrap = ExtensionBootstrap::create();

        // Extension B depends on A
        $extA = new class ($bootOrder) implements ExtensionInterface {
            /** @param ArrayObject<int, string> $order */
            public function __construct(private readonly ArrayObject $order) {}

            public function name(): string
            {
                return 'ext/a';
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void
            {
                $this->order->append('a');
            }

            public function providers(): array
            {
                return [];
            }
        };

        $extB = new class ($bootOrder) implements ExtensionInterface {
            /** @param ArrayObject<int, string> $order */
            public function __construct(private readonly ArrayObject $order) {}

            public function name(): string
            {
                return 'ext/b';
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void
            {
                $this->order->append('b');
            }

            public function providers(): array
            {
                return [];
            }
        };

        $manifestA = ExtensionManifest::fromArray([
            'name' => 'ext/a',
            'version' => '1.0.0', 'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'extension_class' => 'ExtA',
        ]);

        $manifestB = ExtensionManifest::fromArray([
            'name' => 'ext/b',
            'version' => '1.0.0', 'pulsar' => ['min_version' => '1.0.0-rc.11'],
            'extension_class' => 'ExtB',
            'requires' => ['ext/a' => '1.0'],
        ]);

        // Add B first to verify dependency resolution works
        $bootstrap->addExtension($extA, $manifestA);
        $bootstrap->addExtension($extB, $manifestB);

        $kernel = new Kernel(extensionBootstrap: $bootstrap);
        $kernel->boot();

        // Both should boot in order (A before B due to dependency)
        self::assertSame(['a', 'b'], $bootOrder->getArrayCopy());
    }
}

/**
 * Test extension that registers a service and route.
 */
class TestableExtension implements ExtensionInterface
{
    public function name(): string
    {
        return 'test/extension';
    }

    public function register(ContainerInterface $container): void
    {
        $container->bind(TestableService::class, TestableService::class);
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        $router->get('/test', function (ServerRequestInterface $request): Response {
            return Response::json(['message' => 'Hello from extension!']);
        }, 'test.index');
    }

    public function providers(): array
    {
        return [];
    }
}

/**
 * Test service for DI integration testing.
 */
class TestableService
{
    public function getMessage(): string
    {
        return 'Service works!';
    }
}

/**
 * Extension with service provider.
 */
class ExtensionWithProvider implements ExtensionInterface
{
    public function name(): string
    {
        return 'test/provider-extension';
    }

    public function register(ContainerInterface $container): void {}

    public function boot(ContainerInterface $container, RouterInterface $router): void {}

    public function providers(): array
    {
        return [TestServiceProvider::class];
    }
}

/**
 * Test service provider.
 */
class TestServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->bind(ProvidedService::class, fn() => new ProvidedService('Provided by service provider'));
    }

    public function provides(): array
    {
        return [ProvidedService::class];
    }
}

/**
 * Service provided by TestServiceProvider.
 */
class ProvidedService
{
    public function __construct(private readonly string $value) {}

    public function getValue(): string
    {
        return $this->value;
    }
}
