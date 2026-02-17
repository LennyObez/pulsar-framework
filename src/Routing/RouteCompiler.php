<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Closure;
use Pulsar\Api\Api;
use ReflectionClass;

use function array_map;
use function count;
use function is_array;
use function is_string;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function str_contains;
use function str_replace;
use function trim;
use function var_export;

/**
 * Compiles a Router's route collection into a CompiledRouteTree.
 *
 * Static routes (no `{` parameters) are indexed into a hash table
 * for O(1) lookup. Dynamic routes retain pre-compiled regex patterns
 * for fast matching without recompilation at request time.
 *
 * Closure-based handlers are skipped (not serializable).
 */
#[Api(since: '1.0.0')]
final class RouteCompiler
{
    /**
     * Compile all routes from a Router into a CompiledRouteTree.
     *
     * @param list<Route> $routes
     */
    public function compile(array $routes): CompiledRouteTree
    {
        /** @var array<string, array<string, CompiledRouteEntry>> $staticTable */
        $staticTable = [];

        /** @var array<string, list<CompiledDynamicRoute>> $dynamicRoutes */
        $dynamicRoutes = [];

        /** @var array<string, CompiledRouteEntry> $namedRoutes */
        $namedRoutes = [];

        foreach ($routes as $route) {
            if ($route->handler instanceof Closure) {
                continue;
            }

            $handler = $this->normalizeHandler($route->handler);

            if ($handler === null) {
                continue;
            }

            $methodValues = array_map(
                static fn($m) => $m->value,
                $route->methods,
            );

            $entry = new CompiledRouteEntry(
                methods: $methodValues,
                path: $route->path,
                handler: $handler,
                name: $route->name,
                attributes: $route->attributes,
                middleware: $route->middleware,
                constraints: $route->constraints,
                host: $route->host,
            );

            if ($route->name !== null) {
                $namedRoutes[$route->name] = $entry;
            }

            $normalizedPath = '/' . trim($route->path, '/');
            $isDynamic = str_contains($normalizedPath, '{');

            if (!$isDynamic && $route->host === null) {
                // Static route: hash table indexed by method + path
                foreach ($methodValues as $methodValue) {
                    $staticTable[$methodValue][$normalizedPath] = $entry;
                }
            } else {
                // Dynamic route: pre-compile pattern
                $pattern = $this->compilePathPattern($normalizedPath, $route->constraints);
                $hostPattern = null;

                if ($route->host !== null && str_contains($route->host, '{')) {
                    $hostPattern = $this->compileHostPattern($route->host);
                }

                $dynamic = new CompiledDynamicRoute(
                    pattern: $pattern,
                    entry: $entry,
                    host: $route->host,
                    hostPattern: $hostPattern,
                );

                foreach ($methodValues as $methodValue) {
                    $dynamicRoutes[$methodValue][] = $dynamic;
                }
            }
        }

        return new CompiledRouteTree($staticTable, $dynamicRoutes, $namedRoutes);
    }

    /**
     * Export a compiled tree as a PHP string that can be cached and preloaded.
     */
    public function export(CompiledRouteTree $tree): string
    {
        // We serialize to PHP code for opcache preloading
        return "<?php\n\ndeclare(strict_types=1);\n\n// Auto-generated compiled route tree. Do not edit.\nreturn " . var_export($this->treeToArray($tree), true) . ";\n";
    }

    /**
     * Restore a CompiledRouteTree from a cached array.
     *
     * @param array{static: array<string, array<string, array<string, mixed>>>, dynamic: array<string, list<array<string, mixed>>>, named: array<string, array<string, mixed>>} $data
     */
    public function restore(array $data): CompiledRouteTree
    {
        $staticTable = [];

        foreach ($data['static'] as $method => $routes) {
            foreach ($routes as $path => $entryData) {
                $staticTable[$method][$path] = $this->arrayToEntry($entryData);
            }
        }

        $dynamicRoutes = [];

        foreach ($data['dynamic'] as $method => $routes) {
            foreach ($routes as $routeData) {
                /** @var array<string, mixed> $entryData */
                $entryData = is_array($routeData['entry'] ?? null) ? $routeData['entry'] : [];
                $dynamicRoutes[$method][] = new CompiledDynamicRoute(
                    pattern: is_string($routeData['pattern'] ?? null) ? $routeData['pattern'] : '',
                    entry: $this->arrayToEntry($entryData),
                    host: is_string($routeData['host'] ?? null) ? $routeData['host'] : null,
                    hostPattern: is_string($routeData['hostPattern'] ?? null) ? $routeData['hostPattern'] : null,
                );
            }
        }

        $namedRoutes = [];

        foreach ($data['named'] as $name => $entryData) {
            $namedRoutes[$name] = $this->arrayToEntry($entryData);
        }

        return new CompiledRouteTree($staticTable, $dynamicRoutes, $namedRoutes);
    }

    /**
     * @param array<string, string> $constraints
     */
    private function compilePathPattern(string $path, array $constraints): string
    {
        $pattern = preg_quote($path, '#');
        $pattern = str_replace(['\{', '\}'], ['{', '}'], $pattern);

        // Required params: {param}
        $replaced = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)}#',
            static function (array $matches) use ($constraints): string {
                $name = $matches[1];
                $regex = $constraints[$name] ?? '[^/]+';

                return '(?P<' . $name . '>' . $regex . ')';
            },
            $pattern,
        );
        $pattern = $replaced ?? $pattern;

        // Optional params: {param?}
        $replaced = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\?}#',
            static function (array $matches) use ($constraints): string {
                $name = $matches[1];
                $regex = $constraints[$name] ?? '[^/]+';

                return '(?:(?P<' . $name . '>' . $regex . '))?';
            },
            $pattern,
        );
        $pattern = $replaced ?? $pattern;

        return '#^' . $pattern . '$#';
    }

    private function compileHostPattern(string $host): string
    {
        $pattern = preg_quote($host, '#');
        $pattern = str_replace(['\{', '\}'], ['{', '}'], $pattern);

        $replaced = preg_replace(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)}#',
            '(?P<$1>[^.]+)',
            $pattern,
        );

        return '#^' . ($replaced ?? $pattern) . '$#i';
    }

    /**
     * @return string|array{0: class-string, 1: string}|null
     */
    private function normalizeHandler(mixed $handler): string|array|null
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1])) {
            /** @var array{0: class-string, 1: string} $handler */
            return $handler;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function treeToArray(CompiledRouteTree $tree): array
    {
        $staticData = [];
        $dynamicData = [];
        $namedData = [];

        // Use reflection to access private properties for serialization
        $ref = new ReflectionClass($tree);

        $staticProp = $ref->getProperty('staticTable');
        /** @var array<string, array<string, CompiledRouteEntry>> $staticTable */
        $staticTable = $staticProp->getValue($tree);

        foreach ($staticTable as $method => $routes) {
            foreach ($routes as $path => $entry) {
                $staticData[$method][$path] = $this->entryToArray($entry);
            }
        }

        $dynamicProp = $ref->getProperty('dynamicRoutes');
        /** @var array<string, list<CompiledDynamicRoute>> $dynamicRoutesList */
        $dynamicRoutesList = $dynamicProp->getValue($tree);

        foreach ($dynamicRoutesList as $method => $routes) {
            foreach ($routes as $dynamic) {
                $dynamicData[$method][] = [
                    'pattern' => $dynamic->pattern,
                    'entry' => $this->entryToArray($dynamic->entry),
                    'host' => $dynamic->host,
                    'hostPattern' => $dynamic->hostPattern,
                ];
            }
        }

        $namedProp = $ref->getProperty('namedRoutes');
        /** @var array<string, CompiledRouteEntry> $namedRoutesList */
        $namedRoutesList = $namedProp->getValue($tree);

        foreach ($namedRoutesList as $name => $entry) {
            $namedData[$name] = $this->entryToArray($entry);
        }

        return [
            'static' => $staticData,
            'dynamic' => $dynamicData,
            'named' => $namedData,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryToArray(CompiledRouteEntry $entry): array
    {
        return [
            'methods' => $entry->methods,
            'path' => $entry->path,
            'handler' => $entry->handler,
            'name' => $entry->name,
            'attributes' => $entry->attributes,
            'middleware' => $entry->middleware,
            'constraints' => $entry->constraints,
            'host' => $entry->host,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function arrayToEntry(array $data): CompiledRouteEntry
    {
        /** @var list<string> $methods */
        $methods = is_array($data['methods'] ?? null) ? $data['methods'] : [];
        /** @var array{class-string, string}|string $handler */
        $handler = $data['handler'] ?? '';
        /** @var array<string, mixed> $attributes */
        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        /** @var list<string> $middleware */
        $middleware = is_array($data['middleware'] ?? null) ? $data['middleware'] : [];
        /** @var array<string, string> $constraints */
        $constraints = is_array($data['constraints'] ?? null) ? $data['constraints'] : [];

        return new CompiledRouteEntry(
            methods: $methods,
            path: is_string($data['path'] ?? null) ? $data['path'] : '',
            handler: $handler,
            name: is_string($data['name'] ?? null) ? $data['name'] : null,
            attributes: $attributes,
            middleware: $middleware,
            constraints: $constraints,
            host: is_string($data['host'] ?? null) ? $data['host'] : null,
        );
    }
}
