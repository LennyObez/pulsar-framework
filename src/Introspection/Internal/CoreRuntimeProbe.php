<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Internal;

use function array_map;
use function array_values;

use Closure;

use function is_array;
use function is_string;
use function preg_match;

use Pulsar\Api\Internal;
use Pulsar\Console\Application;
use Pulsar\Console\Command;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionRegistry;
use Pulsar\Http\Method;
use Pulsar\Introspection\Data\ArchitectureMapData;
use Pulsar\Introspection\Data\CommandEntry;
use Pulsar\Introspection\Data\CommandReferenceData;
use Pulsar\Introspection\Data\ExtensionEntry;
use Pulsar\Introspection\Data\RouteEntry;
use Pulsar\Introspection\Data\RouteMapData;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;
use Throwable;

/**
 * Crawls Container, ExtensionRegistry, Router, and Console Application
 * to gather runtime-dependent metadata.
 *
 * Only exposes FQCN-like binding keys, route handler class::method
 * references, and extension names/versions — never config values,
 * secrets, or absolute file paths.
 */
#[Internal]
final readonly class CoreRuntimeProbe
{
    /** Matches FQCN-like binding keys only (no service locator keys like db.password). */
    private const string FQCN_PATTERN = '~^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$~';

    public function __construct(
        private ContainerInterface $container,
        private ?ExtensionRegistry $extensionRegistry,
        private ?RouterInterface $router,
        private ?Application $consoleApplication,
    ) {}

    /**
     * Probe the architecture map: extensions + FQCN-only bindings.
     *
     * @param list<string> $warnings Accumulated warnings (by reference)
     */
    public function probeArchitecture(array &$warnings): ArchitectureMapData
    {
        $extensions = $this->probeExtensions($warnings);
        $bindings = $this->probeBindings($warnings);

        return new ArchitectureMapData(
            extensions: $extensions,
            bindings: $bindings,
        );
    }

    /**
     * Probe the route map.
     *
     * @param list<string> $warnings
     */
    public function probeRoutes(array &$warnings): RouteMapData
    {
        if ($this->router === null) {
            $warnings[] = 'Router not available — route map is empty.';

            return new RouteMapData();
        }

        try {
            $routes = $this->router->routes();
        } catch (Throwable $e) {
            $warnings[] = 'Failed to probe routes: ' . $e->getMessage();

            return new RouteMapData();
        }

        $entries = array_map(
            static fn(Route $route): RouteEntry => new RouteEntry(
                methods: array_map(
                    static fn(Method $m): string => $m->value,
                    $route->methods,
                ),
                path: $route->path,
                handler: self::formatHandler($route->handler),
                name: $route->name,
                middleware: $route->middleware,
            ),
            $routes,
        );

        return new RouteMapData(routes: $entries);
    }

    /**
     * Probe the command reference.
     *
     * @param list<string> $warnings
     */
    public function probeCommands(array &$warnings): CommandReferenceData
    {
        if ($this->consoleApplication === null) {
            $warnings[] = 'Console Application not available — command reference is empty.';

            return new CommandReferenceData();
        }

        try {
            $commands = $this->consoleApplication->all();
        } catch (Throwable $e) {
            $warnings[] = 'Failed to probe commands: ' . $e->getMessage();

            return new CommandReferenceData();
        }

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

        return new CommandReferenceData(commands: $entries);
    }

    /**
     * @param list<string> $warnings
     *
     * @return list<ExtensionEntry>
     */
    private function probeExtensions(array &$warnings): array
    {
        if ($this->extensionRegistry === null) {
            $warnings[] = 'ExtensionRegistry not available — extension list is empty.';

            return [];
        }

        try {
            $extensions = $this->extensionRegistry->all();
            $manifests = $this->extensionRegistry->allManifests();
        } catch (Throwable $e) {
            $warnings[] = 'Failed to probe extensions: ' . $e->getMessage();

            return [];
        }

        $entries = [];

        foreach ($extensions as $name => $extension) {
            $manifest = $manifests[$name] ?? null;
            $state = 'unknown';

            try {
                $state = $this->extensionRegistry->getState($name)->value;
            } catch (Throwable) {
                // Swallow — state unavailable
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
    }

    /**
     * @param list<string> $warnings
     *
     * @return list<string>
     */
    private function probeBindings(array &$warnings): array
    {
        try {
            $allBindings = $this->container->getBindings();
        } catch (Throwable $e) {
            $warnings[] = 'Failed to probe container bindings: ' . $e->getMessage();

            return [];
        }

        // Filter to FQCN-like keys only — never expose service-locator keys
        return array_values(
            array_filter(
                $allBindings,
                static fn(string $key): bool => preg_match(self::FQCN_PATTERN, $key) === 1,
            ),
        );
    }

    /**
     * Format a route handler for safe display: Class::method or string representation.
     * Never exposes file paths or closure source locations.
     */
    private static function formatHandler(mixed $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0]) && is_string($handler[1])) {
            return $handler[0] . '::' . $handler[1];
        }

        if ($handler instanceof Closure) {
            return 'Closure';
        }

        return 'unknown';
    }
}
