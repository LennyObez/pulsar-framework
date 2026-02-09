<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Introspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Application;
use Pulsar\Console\Command;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ExtensionLifecycle;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\ExtensionRegistry;
use Pulsar\Http\Method;
use Pulsar\Introspection\Internal\CoreRuntimeProbe;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;
use ReflectionClass;

#[CoversClass(CoreRuntimeProbe::class)]
final class CoreRuntimeProbeTest extends TestCase
{
    #[Test]
    public function probeArchitectureReturnsExtensionsAndBindings(): void
    {
        $container = self::createStub(ContainerInterface::class);
        $container->method('getBindings')->willReturn([
            'Pulsar\\Http\\Kernel',
            'Pulsar\\Routing\\Router',
            'db.connection',
        ]);

        $extension = self::createStub(ExtensionInterface::class);
        $extension->method('name')->willReturn('test-ext');

        $manifest = ExtensionManifest::fromArray([
            'name' => 'test-ext',
            'version' => '1.0.0',
            'extension_class' => 'Pulsar\\TestExtension',
            'provides' => ['services' => ['Pulsar\\SomeService']],
            'requires' => ['core' => '>=1.0.0'],
        ], '/tmp');

        $registry = new ExtensionRegistry();
        $registry->add($extension, $manifest, ExtensionLifecycle::Booted);

        $probe = new CoreRuntimeProbe(
            container: $container,
            extensionRegistry: $registry,
            router: null,
            consoleApplication: null,
        );

        $warnings = [];
        $result = $probe->probeArchitecture($warnings);

        self::assertCount(1, $result->extensions);
        self::assertSame('test-ext', $result->extensions[0]->name);
        self::assertSame('1.0.0', $result->extensions[0]->version);
        self::assertSame('booted', $result->extensions[0]->state);
        self::assertSame(['Pulsar\\SomeService'], $result->extensions[0]->provides);
        self::assertSame(['core'], $result->extensions[0]->dependencies);

        // Only FQCN-like bindings should be present
        self::assertContains('Pulsar\\Http\\Kernel', $result->bindings);
        self::assertContains('Pulsar\\Routing\\Router', $result->bindings);
    }

    #[Test]
    public function probeArchitectureFiltersFqcnOnlyBindings(): void
    {
        $container = self::createStub(ContainerInterface::class);
        $container->method('getBindings')->willReturn([
            'Pulsar\\Http\\Kernel',
            'db.password',
            'cache.store',
            'App\\Service',
        ]);

        $probe = new CoreRuntimeProbe(
            container: $container,
            extensionRegistry: null,
            router: null,
            consoleApplication: null,
        );

        $warnings = [];
        $result = $probe->probeArchitecture($warnings);

        self::assertContains('Pulsar\\Http\\Kernel', $result->bindings);
        self::assertContains('App\\Service', $result->bindings);
        self::assertNotContains('db.password', $result->bindings);
        self::assertNotContains('cache.store', $result->bindings);
    }

    #[Test]
    public function probeRoutesReturnsFormattedEntries(): void
    {
        /** @var callable(): mixed $handler */
        $handler = 'App\\Controller\\UserController::show';

        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/users/{id}',
            handler: $handler,
            name: 'users.show',
            middleware: ['auth'],
        );

        $router = self::createStub(RouterInterface::class);
        $router->method('routes')->willReturn([$route]);

        $container = self::createStub(ContainerInterface::class);
        $container->method('getBindings')->willReturn([]);

        $probe = new CoreRuntimeProbe(
            container: $container,
            extensionRegistry: null,
            router: $router,
            consoleApplication: null,
        );

        $warnings = [];
        $result = $probe->probeRoutes($warnings);

        self::assertSame([], $warnings);
        self::assertCount(1, $result->routes);

        $entry = $result->routes[0];
        self::assertSame(['GET', 'HEAD'], $entry->methods);
        self::assertSame('/users/{id}', $entry->path);
        self::assertSame('App\\Controller\\UserController::show', $entry->handler);
        self::assertSame('users.show', $entry->name);
        self::assertSame(['auth'], $entry->middleware);
    }

    #[Test]
    public function probeCommandsReturnsEntries(): void
    {
        $command = new class extends Command {
            protected function configure(): void
            {
                $this->name = 'make:model';
                $this->description = 'Create a new model class';
                $this->addArgument('name', 'The model name', true);
                $this->addOption('migration', 'Also create a migration', 'm');
            }

            public function execute(InputInterface $input, OutputInterface $output): int
            {
                return 0;
            }
        };

        // Application and Kernel are both final — use Reflection to bypass constructor
        $reflection = new ReflectionClass(Application::class);
        $app = $reflection->newInstanceWithoutConstructor();
        $app->add($command);

        $container = self::createStub(ContainerInterface::class);
        $container->method('getBindings')->willReturn([]);

        $probe = new CoreRuntimeProbe(
            container: $container,
            extensionRegistry: null,
            router: null,
            consoleApplication: $app,
        );

        $warnings = [];
        $result = $probe->probeCommands($warnings);

        self::assertSame([], $warnings);
        self::assertCount(1, $result->commands);

        $entry = $result->commands[0];
        self::assertSame('make:model', $entry->name);
        self::assertSame('Create a new model class', $entry->description);
        self::assertCount(1, $entry->arguments);
        self::assertSame('name', $entry->arguments[0]['name']);
        self::assertTrue($entry->arguments[0]['required']);
        self::assertArrayHasKey('migration', $entry->options);
    }

    #[Test]
    public function probeGracefullyHandlesNullDependencies(): void
    {
        $container = self::createStub(ContainerInterface::class);
        $container->method('getBindings')->willReturn([]);

        $probe = new CoreRuntimeProbe(
            container: $container,
            extensionRegistry: null,
            router: null,
            consoleApplication: null,
        );

        $warnings = [];

        $routes = $probe->probeRoutes($warnings);
        self::assertSame([], $routes->routes);

        $commands = $probe->probeCommands($warnings);
        self::assertSame([], $commands->commands);

        $architecture = $probe->probeArchitecture($warnings);
        self::assertSame([], $architecture->extensions);

        self::assertNotEmpty($warnings);

        $warningText = implode(' | ', $warnings);
        self::assertStringContainsString('Router not available', $warningText);
        self::assertStringContainsString('Console Application not available', $warningText);
        self::assertStringContainsString('ExtensionRegistry not available', $warningText);
    }
}
