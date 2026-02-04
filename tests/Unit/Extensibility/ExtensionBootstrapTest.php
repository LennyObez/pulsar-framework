<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionLifecycle;
use Pulsar\Extensibility\ExtensionLoader;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\ExtensionRegistry;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;

#[CoversClass(ExtensionBootstrap::class)]
final class ExtensionBootstrapTest extends TestCase
{
    private ExtensionBootstrap $bootstrap;
    private Container $container;
    private Router $router;

    protected function setUp(): void
    {
        $this->bootstrap = ExtensionBootstrap::create();
        $this->container = new Container();
        $this->router = new Router();
    }

    #[Test]
    public function createReturnsConfiguredInstance(): void
    {
        $bootstrap = ExtensionBootstrap::create();

        self::assertInstanceOf(ExtensionRegistry::class, $bootstrap->registry);
        self::assertInstanceOf(ExtensionLoader::class, $bootstrap->loader);
    }

    #[Test]
    public function addExtensionRegistersInRegistry(): void
    {
        $extension = $this->createTestExtension('test/ext');
        $manifest = $this->createManifest('test/ext');

        $this->bootstrap->addExtension($extension, $manifest);

        self::assertTrue($this->bootstrap->registry->has('test/ext'));
    }

    #[Test]
    public function registerCallsExtensionRegister(): void
    {
        $extension = new class implements ExtensionInterface {
            public bool $registered = false;

            public function name(): string
            {
                return 'test/ext';
            }

            public function register(ContainerInterface $container): void
            {
                $this->registered = true;
            }

            public function boot(ContainerInterface $container, RouterInterface $router): void {}

            public function providers(): array
            {
                return [];
            }
        };

        $this->bootstrap->addExtension($extension, $this->createManifest('test/ext'));
        $this->bootstrap->register($this->container);

        self::assertTrue($extension->registered);
    }

    #[Test]
    public function registerIsIdempotent(): void
    {
        $callCount = 0;
        $extension = new class ($callCount) implements ExtensionInterface {
            public function __construct(private int &$callCount) {}

            public function name(): string
            {
                return 'test/ext';
            }

            public function register(ContainerInterface $container): void
            {
                $this->callCount++;
            }

            public function boot(ContainerInterface $container, RouterInterface $router): void {}

            public function providers(): array
            {
                return [];
            }
        };

        $this->bootstrap->addExtension($extension, $this->createManifest('test/ext'));
        $this->bootstrap->register($this->container);
        $this->bootstrap->register($this->container);

        self::assertSame(1, $callCount);
    }

    #[Test]
    public function bootCallsExtensionBoot(): void
    {
        $extension = new class implements ExtensionInterface {
            public bool $booted = false;

            public function name(): string
            {
                return 'test/ext';
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void
            {
                $this->booted = true;
            }

            public function providers(): array
            {
                return [];
            }
        };

        $this->bootstrap->addExtension($extension, $this->createManifest('test/ext'));
        $this->bootstrap->register($this->container);
        $this->bootstrap->boot($this->container, $this->router);

        self::assertTrue($extension->booted);
    }

    #[Test]
    public function bootThrowsIfNotRegistered(): void
    {
        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('must be registered before booting');

        $this->bootstrap->boot($this->container, $this->router);
    }

    #[Test]
    public function bootIsIdempotent(): void
    {
        $callCount = 0;
        $extension = new class ($callCount) implements ExtensionInterface {
            public function __construct(private int &$callCount) {}

            public function name(): string
            {
                return 'test/ext';
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void
            {
                $this->callCount++;
            }

            public function providers(): array
            {
                return [];
            }
        };

        $this->bootstrap->addExtension($extension, $this->createManifest('test/ext'));
        $this->bootstrap->register($this->container);
        $this->bootstrap->boot($this->container, $this->router);
        $this->bootstrap->boot($this->container, $this->router);

        self::assertSame(1, $callCount);
    }

    #[Test]
    public function registerCallsServiceProviders(): void
    {
        $providerCalled = false;
        TestServiceProvider::$registerCallback = function () use (&$providerCalled) {
            $providerCalled = true;
        };

        $extension = new class implements ExtensionInterface {
            public function name(): string
            {
                return 'test/ext';
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void {}

            public function providers(): array
            {
                return [TestServiceProvider::class];
            }
        };

        $this->bootstrap->addExtension($extension, $this->createManifest('test/ext'));
        $this->bootstrap->register($this->container);

        self::assertTrue($providerCalled);
    }

    #[Test]
    public function lifecycleStateTransitions(): void
    {
        $extension = $this->createTestExtension('test/ext');
        $this->bootstrap->addExtension($extension, $this->createManifest('test/ext'));

        self::assertSame(
            ExtensionLifecycle::Validated,
            $this->bootstrap->registry->getState('test/ext'),
        );

        $this->bootstrap->register($this->container);
        self::assertSame(
            ExtensionLifecycle::Registered,
            $this->bootstrap->registry->getState('test/ext'),
        );

        $this->bootstrap->boot($this->container, $this->router);
        self::assertSame(
            ExtensionLifecycle::Booted,
            $this->bootstrap->registry->getState('test/ext'),
        );
    }

    #[Test]
    public function isRegisteredReturnsCorrectState(): void
    {
        $extension = $this->createTestExtension('test/ext');
        $this->bootstrap->addExtension($extension, $this->createManifest('test/ext'));

        self::assertFalse($this->bootstrap->registered);

        $this->bootstrap->register($this->container);
        self::assertTrue($this->bootstrap->registered);
    }

    #[Test]
    public function isBootedReturnsCorrectState(): void
    {
        $extension = $this->createTestExtension('test/ext');
        $this->bootstrap->addExtension($extension, $this->createManifest('test/ext'));
        $this->bootstrap->register($this->container);

        self::assertFalse($this->bootstrap->booted);

        $this->bootstrap->boot($this->container, $this->router);
        self::assertTrue($this->bootstrap->booted);
    }

    #[Test]
    public function getCommandsReturnsExtensionCommands(): void
    {
        $manifest = ExtensionManifest::fromArray([
            'name' => 'test/ext',
            'version' => '1.0.0',
            'extension_class' => 'Test',
            'provides' => [
                'commands' => ['TestCommand', 'OtherCommand'],
            ],
        ]);

        $this->bootstrap->addExtension($this->createTestExtension('test/ext'), $manifest);

        $commands = $this->bootstrap->getCommands();

        self::assertSame(['TestCommand', 'OtherCommand'], $commands);
    }

    #[Test]
    public function bootPassesRouterInterfaceToExtension(): void
    {
        $extension = new class implements ExtensionInterface {
            public bool $booted = false;

            public function name(): string
            {
                return 'test/router-interface';
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void
            {
                $this->booted = true;
                $router->get('/interface-test', fn() => 'ok', 'interface.test');
            }

            public function providers(): array
            {
                return [];
            }
        };

        $this->bootstrap->addExtension($extension, $this->createManifest('test/router-interface'));
        $this->bootstrap->register($this->container);
        $this->bootstrap->boot($this->container, $this->router);

        self::assertTrue($extension->booted);
        self::assertSame(1, $this->router->count());
        self::assertSame('/interface-test', $this->router->routes[0]->path);
    }

    #[Test]
    public function preBootCalledBeforeBoot(): void
    {
        $order = [];

        $extension = new class ($order) implements ExtensionInterface, PreBootExtensionInterface {
            /** @param list<string> $order */
            public function __construct(private array &$order) {} // @phpstan-ignore property.onlyWritten

            public function name(): string
            {
                return 'test/preboot';
            }

            public function register(ContainerInterface $container): void {}

            public function preBoot(ContainerInterface $container): void
            {
                $this->order[] = 'preBoot';
            }

            public function boot(ContainerInterface $container, RouterInterface $router): void
            {
                $this->order[] = 'boot';
            }

            public function providers(): array
            {
                return [];
            }
        };

        $this->bootstrap->addExtension($extension, $this->createManifest('test/preboot'));
        $this->bootstrap->register($this->container);
        $this->bootstrap->boot($this->container, $this->router);

        self::assertSame(['preBoot', 'boot'], $order);
    }

    #[Test]
    public function postBootCalledAfterAllBoots(): void
    {
        $order = [];

        $extensionA = new class ($order) implements ExtensionInterface, PostBootExtensionInterface {
            /** @param list<string> $order */
            public function __construct(private array &$order) {} // @phpstan-ignore property.onlyWritten

            public function name(): string
            {
                return 'test/a';
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void
            {
                $this->order[] = 'boot:a';
            }

            public function postBoot(ContainerInterface $container): void
            {
                $this->order[] = 'postBoot:a';
            }

            public function providers(): array
            {
                return [];
            }
        };

        $extensionB = new class ($order) implements ExtensionInterface {
            /** @param list<string> $order */
            public function __construct(private array &$order) {} // @phpstan-ignore property.onlyWritten

            public function name(): string
            {
                return 'test/b';
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void
            {
                $this->order[] = 'boot:b';
            }

            public function providers(): array
            {
                return [];
            }
        };

        $this->bootstrap->addExtension($extensionA, $this->createManifest('test/a'));
        $this->bootstrap->addExtension($extensionB, $this->createManifest('test/b'));
        $this->bootstrap->register($this->container);
        $this->bootstrap->boot($this->container, $this->router);

        self::assertSame(['boot:a', 'boot:b', 'postBoot:a'], $order);
    }

    #[Test]
    public function preBootAndPostBootAreOptional(): void
    {
        $extension = new class implements ExtensionInterface {
            public bool $booted = false;

            public function name(): string
            {
                return 'test/plain';
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void
            {
                $this->booted = true;
            }

            public function providers(): array
            {
                return [];
            }
        };

        $this->bootstrap->addExtension($extension, $this->createManifest('test/plain'));
        $this->bootstrap->register($this->container);
        $this->bootstrap->boot($this->container, $this->router);

        self::assertTrue($extension->booted);
    }

    private function createTestExtension(string $name): ExtensionInterface
    {
        return new class ($name) implements ExtensionInterface {
            public function __construct(private readonly string $extensionName) {}

            public function name(): string
            {
                return $this->extensionName;
            }

            public function register(ContainerInterface $container): void {}

            public function boot(ContainerInterface $container, RouterInterface $router): void {}

            public function providers(): array
            {
                return [];
            }
        };
    }

    private function createManifest(string $name): ExtensionManifest
    {
        return ExtensionManifest::fromArray([
            'name' => $name,
            'version' => '1.0.0',
            'extension_class' => 'TestExtension',
        ]);
    }
}

/**
 * Test service provider for testing provider registration.
 */
class TestServiceProvider implements ServiceProviderInterface
{
    /** @var callable|null */
    public static $registerCallback = null;

    public function register(ContainerInterface $container): void
    {
        if (self::$registerCallback !== null) {
            (self::$registerCallback)();
        }
    }

    public function provides(): array
    {
        return [];
    }
}
