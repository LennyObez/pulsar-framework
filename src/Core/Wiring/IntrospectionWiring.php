<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use const DIRECTORY_SEPARATOR;

use function dirname;
use function is_array;
use function is_file;
use function is_object;
use function is_string;

use Pulsar\Api\Internal;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Console\Application;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionRegistry;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Introspection\Internal\ConfigSchemaReflector;
use Pulsar\Introspection\Internal\CoreRuntimeProbe;
use Pulsar\Introspection\Internal\SnapshotFileReader;
use Pulsar\Introspection\IntrospectionConfig;
use Pulsar\Introspection\ProjectMetadataService;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;

/**
 * Wires the Introspection module into the kernel boot pipeline.
 */
#[Internal]
final readonly class IntrospectionWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();
        $environment = $configManager->environment();

        /** @var AppConfig $appConfig */
        $appConfig = $repository->get(AppConfig::class);

        // Load introspection config
        $configPath = $configManager->configPath();
        $configData = [];

        if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'introspection.php')) {
            /** @psalm-suppress UnresolvableInclude */
            $loaded = require $configPath . DIRECTORY_SEPARATOR . 'introspection.php';

            if (is_array($loaded)) {
                /** @var array<string, mixed> $loaded */
                $configData = $loaded;
            }
        }

        $config = IntrospectionConfig::fromArray($configData, $environment, $appConfig->mode);
        $container->instance(IntrospectionConfig::class, $config);

        if (!$config->enabled) {
            return;
        }

        // SensitiveDataScrubber (reuse if available, otherwise create)
        $scrubber = $container->has(SensitiveDataScrubber::class)
            ? $container->get(SensitiveDataScrubber::class)
            : new SensitiveDataScrubber();
        /** @var SensitiveDataScrubber $scrubber */

        // CoreRuntimeProbe
        $extensionRegistry = $container->has(ExtensionRegistry::class)
            ? $container->get(ExtensionRegistry::class)
            : null;
        /** @var ExtensionRegistry|null $extensionRegistry */

        $routerInterface = $container->has(RouterInterface::class)
            ? $container->get(RouterInterface::class)
            : $router;
        /** @var RouterInterface $routerInterface */

        $consoleApp = $container->has(Application::class)
            ? $container->get(Application::class)
            : null;
        /** @var Application|null $consoleApp */

        $probe = new CoreRuntimeProbe(
            container: $container,
            extensionRegistry: $extensionRegistry,
            router: $routerInterface,
            consoleApplication: $consoleApp,
        );

        // ConfigSchemaReflector
        $schemaReflector = new ConfigSchemaReflector($scrubber);

        // SnapshotFileReader
        $configPath = $configManager->configPath();
        $projectRoot = $configPath !== null
            ? dirname($configPath)
            : (getcwd() ?: '.');
        $snapshotReader = new SnapshotFileReader($projectRoot);

        // Collect known config DTO classes
        /** @var list<class-string> $configClasses */
        $configClasses = $this->discoverConfigClasses($repository);

        // ProjectMetadataService
        $service = new ProjectMetadataService(
            probe: $probe,
            schemaReflector: $schemaReflector,
            snapshotReader: $snapshotReader,
            scrubber: $scrubber,
            contributors: [],
            configClasses: $configClasses,
        );

        $container->instance(ProjectMetadataService::class, $service);
    }

    /**
     * Discover registered config DTO class names from the repository.
     *
     * @return list<class-string>
     */
    private function discoverConfigClasses(mixed $repository): array
    {
        // The repository stores configs keyed by class name
        if (!is_object($repository) || !method_exists($repository, 'all')) {
            return [];
        }

        /** @var array<string, mixed> $all */
        $all = $repository->all();

        $classes = [];

        foreach (array_keys($all) as $key) {
            if (is_string($key) && class_exists($key)) {
                /** @var class-string $key */
                $classes[] = $key;
            }
        }

        return $classes;
    }
}
