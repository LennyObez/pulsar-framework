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
    public function registerExposesExtensionRegistryInContainer(): void
    {
        $extension = new class implements ExtensionInterface {
            public function name(): string
            {
                return 'test/ext';
            }
            public function register(ContainerInterface $container): void {}
            public function boot(ContainerInterface $container, RouterInterface $router): void {}
            public function providers(): array
            {
                return [];
            }
        };

        $this->bootstrap->addExtension($extension, $this->createManifest('test/ext'));
        $this->bootstrap->register($this->container);

        self::assertTrue($this->container->has(ExtensionRegistry::class));
        self::assertInstanceOf(ExtensionRegistry::class, $this->container->get(ExtensionRegistry::class));
    }

    #[Test]
    public function registeredExtensionRegistryContainsLoadedManifests(): void
    {
        $extension = new class implements ExtensionInterface {
            public function name(): string
            {
                return 'test/ext';
            }
            public function register(ContainerInterface $container): void {}
            public function boot(ContainerInterface $container, RouterInterface $router): void {}
            public function providers(): array
            {
                return [];
            }
        };

        $manifest = $this->createManifest('test/ext');
        $this->bootstrap->addExtension($extension, $manifest);
        $this->bootstrap->register($this->container);

        /** @var ExtensionRegistry $registry */
        $registry = $this->container->get(ExtensionRegistry::class);
        $manifests = $registry->allManifests();

        self::assertArrayHasKey('test/ext', $manifests);
        self::assertSame('test/ext', $manifests['test/ext']->name);
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
    public function resetLifecycleAllowsRebootWithoutReRegistering(): void
    {
        $registerCount = 0;
        $bootCount = 0;
        $extension = new class ($registerCount, $bootCount) implements ExtensionInterface {
            public function __construct(private int &$registerCount, private int &$bootCount) {}

            public function name(): string
            {
                return 'test/ext';
            }

            public function register(ContainerInterface $container): void
            {
                $this->registerCount++;
            }

            public function boot(ContainerInterface $container, RouterInterface $router): void
            {
                $this->bootCount++;
            }

            public function providers(): array
            {
                return [];
            }
        };

        $this->bootstrap->addExtension($extension, $this->createManifest('test/ext'));
        $this->bootstrap->register($this->container);
        $this->bootstrap->boot($this->container, $this->router);

        self::assertSame(1, $registerCount);
        self::assertSame(1, $bootCount);
        self::assertTrue($this->bootstrap->booted);
        self::assertSame(ExtensionLifecycle::Booted, $this->bootstrap->registry->getState('test/ext'));

        $this->bootstrap->resetLifecycle();

        // Boot phase reset; registration retained, state dropped Booted -> Registered.
        self::assertFalse($this->bootstrap->booted);
        self::assertTrue($this->bootstrap->registered);
        self::assertSame(ExtensionLifecycle::Registered, $this->bootstrap->registry->getState('test/ext'));

        // Re-boot runs boot() again but does NOT re-run register().
        $this->bootstrap->boot($this->container, $this->router);

        self::assertSame(1, $registerCount);
        self::assertSame(2, $bootCount);
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
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
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

    #[Test]
    public function loadFromPathsSkipsInvalidExtensionsAndContinues(): void
    {
        // Create a temp directory structure with two extensions:
        // one valid (class-not-found), one with invalid JSON
        $tempDir = sys_get_temp_dir() . '/pulsar_test_load_' . bin2hex(random_bytes(4));
        mkdir($tempDir . '/good-ext', 0o755, true);
        mkdir($tempDir . '/bad-ext', 0o755, true);

        // Good extension with a class that doesn't exist but will be caught
        file_put_contents($tempDir . '/good-ext/pulsar.json', json_encode([
            'name' => 'test/good',
            'version' => '1.0.0',
            'extension_class' => 'NonExistentGoodClass',
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
        ]));

        // Bad extension with invalid JSON (parse error during discovery)
        file_put_contents($tempDir . '/bad-ext/pulsar.json', '{invalid json}');

        try {
            // Should not throw: invalid JSON triggers discovery fallback,
            // and class-not-found is collected as a warning
            $this->bootstrap->loadFromPaths([$tempDir]);

            // Warnings include both the discovery error for invalid JSON
            // and the class-not-found warning for the good extension
            $warnings = $this->bootstrap->getLoadWarnings();
            self::assertNotEmpty($warnings, 'Expected at least one load warning for the invalid extension');
        } finally {
            // Cleanup
            @unlink($tempDir . '/good-ext/pulsar.json');
            @unlink($tempDir . '/bad-ext/pulsar.json');
            @rmdir($tempDir . '/good-ext');
            @rmdir($tempDir . '/bad-ext');
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function loadFromPathsRecordsWarningsForSkippedExtensions(): void
    {
        // Create extension with non-existent class
        $tempDir = sys_get_temp_dir() . '/pulsar_test_warn_' . bin2hex(random_bytes(4));
        mkdir($tempDir . '/broken-ext', 0o755, true);

        file_put_contents($tempDir . '/broken-ext/pulsar.json', json_encode([
            'name' => 'test/broken',
            'version' => '1.0.0',
            'extension_class' => 'Pulsar\\NonExistent\\BrokenExtension',
            'pulsar' => ['min_version' => '0.1.0'],
        ]));

        try {
            $this->bootstrap->loadFromPaths([$tempDir]);

            $warnings = $this->bootstrap->getLoadWarnings();
            self::assertNotEmpty($warnings);
            self::assertStringContainsString('test/broken', $warnings[0]);
            self::assertStringContainsString('class not found', $warnings[0]);
        } finally {
            @unlink($tempDir . '/broken-ext/pulsar.json');
            @rmdir($tempDir . '/broken-ext');
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function getLoadWarningsReturnsEmptyBeforeLoading(): void
    {
        self::assertSame([], $this->bootstrap->getLoadWarnings());
    }

    #[Test]
    public function setEnabledFilterRestrictsLoadedExtensions(): void
    {
        $extAlpha = $this->createTestExtension('test/alpha');
        $extBeta = $this->createTestExtension('test/beta');

        $this->bootstrap->addExtension($extAlpha, $this->createManifest('test/alpha'));
        $this->bootstrap->addExtension($extBeta, $this->createManifest('test/beta'));

        // Both extensions are added, verify
        self::assertTrue($this->bootstrap->registry->has('test/alpha'));
        self::assertTrue($this->bootstrap->registry->has('test/beta'));
    }

    #[Test]
    public function setEnabledFilterToNullLoadsAll(): void
    {
        $this->bootstrap->setEnabledFilter(null);

        $extA = $this->createTestExtension('test/a');
        $extB = $this->createTestExtension('test/b');
        $this->bootstrap->addExtension($extA, $this->createManifest('test/a'));
        $this->bootstrap->addExtension($extB, $this->createManifest('test/b'));

        self::assertCount(2, $this->bootstrap->registry->all());
    }

    #[Test]
    public function setEnabledFilterRecordsDisabledExtensionsAsWarnings(): void
    {
        // Two extensions on disk; only one is enabled by the filter. The
        // excluded one must surface as a load warning so an operator can tell
        // "disabled by config" apart from "missing" or "failed to load".
        $tempDir = sys_get_temp_dir() . '/pulsar_test_disabled_' . bin2hex(random_bytes(4));
        mkdir($tempDir . '/keep', 0o755, true);
        mkdir($tempDir . '/drop', 0o755, true);

        file_put_contents($tempDir . '/keep/pulsar.json', json_encode([
            'name' => 'test/keep',
            'version' => '1.0.0',
            'extension_class' => 'Pulsar\\NonExistent\\KeepExtension',
            'pulsar' => ['min_version' => '0.1.0'],
        ]));
        file_put_contents($tempDir . '/drop/pulsar.json', json_encode([
            'name' => 'test/drop',
            'version' => '1.0.0',
            'extension_class' => 'Pulsar\\NonExistent\\DropExtension',
            'pulsar' => ['min_version' => '0.1.0'],
        ]));

        try {
            $this->bootstrap->setEnabledFilter(['test/keep']);
            $this->bootstrap->loadFromPaths([$tempDir]);

            $warnings = $this->bootstrap->getLoadWarnings();
            $disabled = array_filter(
                $warnings,
                static fn(string $w): bool => str_contains($w, 'disabled by config'),
            );

            self::assertCount(1, $disabled, 'expected exactly one disabled-by-config warning');
            self::assertStringContainsString('test/drop', (string) array_values($disabled)[0]);
            self::assertStringNotContainsString('test/keep', (string) array_values($disabled)[0]);
        } finally {
            @unlink($tempDir . '/keep/pulsar.json');
            @unlink($tempDir . '/drop/pulsar.json');
            @rmdir($tempDir . '/keep');
            @rmdir($tempDir . '/drop');
            @rmdir($tempDir);
        }
    }

    #[Test]
    public function setEnabledFilterWithEmptyListLoadsNone(): void
    {
        // Create temp directory with a valid-looking extension
        $tempDir = sys_get_temp_dir() . '/pulsar_test_filter_' . bin2hex(random_bytes(4));
        mkdir($tempDir . '/test-ext', 0o755, true);

        file_put_contents($tempDir . '/test-ext/pulsar.json', json_encode([
            'name' => 'test/filtered',
            'version' => '1.0.0',
            'extension_class' => 'Pulsar\\NonExistent\\FilteredExtension',
            'pulsar' => ['min_version' => '0.1.0'],
        ]));

        try {
            $this->bootstrap->setEnabledFilter([]);
            $this->bootstrap->loadFromPaths([$tempDir]);

            // No extensions should have been loaded
            self::assertSame([], $this->bootstrap->registry->all());
        } finally {
            @unlink($tempDir . '/test-ext/pulsar.json');
            @rmdir($tempDir . '/test-ext');
            @rmdir($tempDir);
        }
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
            'pulsar' => ['min_version' => '1.0.0-rc.11'],
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
