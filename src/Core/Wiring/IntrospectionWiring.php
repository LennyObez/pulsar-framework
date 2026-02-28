<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Console\Application;
use Pulsar\Console\Command;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionRegistry;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Introspection\Data\CommandEntry;
use Pulsar\Introspection\Data\ExtensionEntry;
use Pulsar\Introspection\Internal\ConfigSchemaReflector;
use Pulsar\Introspection\Internal\CoreRuntimeProbe;
use Pulsar\Introspection\Internal\SnapshotFileReader;
use Pulsar\Introspection\IntrospectionConfig;
use Pulsar\Introspection\ProjectMetadataService;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;
use Throwable;

use function dirname;
use function is_array;
use function is_file;
use function is_string;

use const DIRECTORY_SEPARATOR;

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

        // CoreRuntimeProbe: closures decouple from #[Internal] cross-module types
        $extensionProber = $container->has(ExtensionRegistry::class)
            ? $this->buildExtensionProber($container)
            : null;

        $routerInterface = $container->has(RouterInterface::class)
            ? $container->get(RouterInterface::class)
            : $router;
        /** @var RouterInterface $routerInterface */

        $commandProber = $container->has(Application::class)
            ? $this->buildCommandProber($container)
            : null;

        $probe = new CoreRuntimeProbe(
            container: $container,
            extensionProber: $extensionProber,
            router: $routerInterface,
            commandProber: $commandProber,
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
     * Build a closure that extracts extension entries from the registry.
     * The closure captures ExtensionRegistry (an #[Internal] type) so that
     * CoreRuntimeProbe does not need to import it directly.
     *
     * @return Closure(): list<ExtensionEntry>
     */
    private function buildExtensionProber(ContainerInterface $container): Closure
    {
        return static function () use ($container): array {
            /** @var ExtensionRegistry $registry */
            $registry = $container->get(ExtensionRegistry::class);
            $extensions = $registry->all();
            $manifests = $registry->allManifests();
            $entries = [];

            foreach ($extensions as $name => $_extension) {
                $manifest = $manifests[$name] ?? null;
                $state = 'unknown';

                try {
                    $state = $registry->getState($name)->value;
                } catch (Throwable) {
                    // Swallow: state unavailable
                }

                $provides = [];
                $dependencies = [];

                if ($manifest !== null) {
                    $provides = $manifest->provides->services;
                    $dependencies = $manifest->getDependencies();
                }

                $entries[] = new ExtensionEntry(
                    name: $name,
                    version: $manifest->version ?? 'unknown',
                    state: $state,
                    provides: $provides,
                    dependencies: $dependencies,
                );
            }

            return $entries;
        };
    }

    /**
     * Build a closure that extracts command entries from the console application.
     * The closure captures Application (an #[Internal] type) so that
     * CoreRuntimeProbe does not need to import it directly.
     *
     * @return Closure(): list<CommandEntry>
     */
    private function buildCommandProber(ContainerInterface $container): Closure
    {
        return static function () use ($container): array {
            /** @var Application $app */
            $app = $container->get(Application::class);
            $commands = $app->all();
            $entries = [];

            foreach ($commands as $command) {
                $arguments = [];
                $options = [];

                if ($command instanceof Command) {
                    $arguments = $command->arguments;
                    $options = $command->options;
                }

                $entries[] = new CommandEntry(
                    name: $command->name,
                    description: $command->description,
                    arguments: $arguments,
                    options: $options,
                );
            }

            return $entries;
        };
    }

    /**
     * Discover registered config DTO class names from the repository.
     *
     * @param object $repository Config repository (duck-typed for `all()` method)
     * @return list<class-string>
     */
    private function discoverConfigClasses(object $repository): array
    {
        // The repository stores configs keyed by class name
        if (!method_exists($repository, 'all')) {
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
