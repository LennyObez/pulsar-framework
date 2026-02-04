<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use function array_filter;

use const ARRAY_FILTER_USE_KEY;

use function in_array;

use Pulsar\Http\Method;

/**
 * Represents a single route definition.
 */
readonly class Route
{
    /**
     * @param list<Method> $methods Allowed HTTP methods
     * @param string $path The route path pattern
     * @param callable|class-string|array{0: class-string, 1: string} $handler The route handler
     * @param string|null $name Optional route name
     * @param array<string, mixed> $attributes Additional route attributes
     * @param list<string> $middleware Middleware to apply
     * @param array<string, string> $constraints Regex constraints per parameter (e.g. ['id' => '\d+'])
     * @param string|null $host Host pattern for host-based routing (e.g. 'api.example.com' or '{subdomain}.example.com')
     */
    public function __construct(
        public array $methods,
        public string $path,
        public mixed $handler,
        public ?string $name = null,
        public array $attributes = [],
        public array $middleware = [],
        public array $constraints = [],
        public ?string $host = null,
    ) {}

    /**
     * Check if this route matches the given method.
     */
    public function matchesMethod(Method $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    /**
     * Check if this route matches the given path.
     *
     * Returns extracted parameters on match, null on no match.
     *
     * @psalm-suppress MixedReturnTypeCoercion array_filter with is_string key filter guarantees string keys
     *
     * @return array<string, string>|null
     */
    public function matchesPath(string $path): ?array
    {
        // Normalize paths
        $routePath = '/' . trim($this->path, '/');
        $requestPath = '/' . trim($path, '/');

        // Exact match (no parameters)
        if (!str_contains($routePath, '{')) {
            return $routePath === $requestPath ? [] : null;
        }

        // Build regex pattern from route path
        $pattern = $this->pathToPattern($routePath);

        if (preg_match($pattern, $requestPath, $matches)) {
            // Extract named parameters (filter out numeric keys from preg_match)
            /** @psalm-suppress MixedReturnTypeCoercion array_filter with is_string key filter guarantees string keys */
            return array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);
        }

        return null;
    }

    /**
     * Convert route path to regex pattern, applying per-parameter constraints.
     */
    private function pathToPattern(string $path): string
    {
        // Escape regex special characters except { and }
        $pattern = preg_quote($path, '#');

        // Restore { and } and convert to named capture groups
        $pattern = str_replace(['\{', '\}'], ['{', '}'], $pattern);

        $constraints = $this->constraints;

        // Convert {param} to (?P<param>CONSTRAINT) using constraints or default [^/]+
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

        // Convert {param?} to (?:(?P<param>CONSTRAINT))? using constraints or default [^/]+
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

    /**
     * Check if this route matches the given host.
     *
     * Returns extracted host parameters on match, null on no match.
     * Routes without a host pattern match any host (returns empty array).
     *
     * @psalm-suppress MixedReturnTypeCoercion array_filter with is_string key filter guarantees string keys
     *
     * @return array<string, string>|null
     */
    public function matchesHost(string $host): ?array
    {
        if ($this->host === null) {
            return [];
        }

        // Exact match (no parameters)
        if (!str_contains($this->host, '{')) {
            return strtolower($this->host) === strtolower($host) ? [] : null;
        }

        // Build regex from host pattern
        $pattern = preg_quote($this->host, '#');
        $pattern = str_replace(['\{', '\}'], ['{', '}'], $pattern);

        $replaced = preg_replace(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)}#',
            '(?P<$1>[^.]+)',
            $pattern,
        );
        $pattern = '#^' . ($replaced ?? $pattern) . '$#i';

        if (preg_match($pattern, $host, $matches)) {
            /** @psalm-suppress MixedReturnTypeCoercion array_filter with is_string key filter guarantees string keys */
            return array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);
        }

        return null;
    }

    /**
     * Create a GET route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public static function get(string $path, mixed $handler, ?string $name = null): self
    {
        return new self([Method::GET, Method::HEAD], $path, $handler, $name);
    }

    /**
     * Create a POST route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public static function post(string $path, mixed $handler, ?string $name = null): self
    {
        return new self([Method::POST], $path, $handler, $name);
    }

    /**
     * Create a PUT route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public static function put(string $path, mixed $handler, ?string $name = null): self
    {
        return new self([Method::PUT], $path, $handler, $name);
    }

    /**
     * Create a PATCH route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public static function patch(string $path, mixed $handler, ?string $name = null): self
    {
        return new self([Method::PATCH], $path, $handler, $name);
    }

    /**
     * Create a DELETE route.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public static function delete(string $path, mixed $handler, ?string $name = null): self
    {
        return new self([Method::DELETE], $path, $handler, $name);
    }

    /**
     * Create a route matching any method.
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public static function any(string $path, mixed $handler, ?string $name = null): self
    {
        return new self(
            [Method::GET, Method::HEAD, Method::POST, Method::PUT, Method::PATCH, Method::DELETE, Method::OPTIONS],
            $path,
            $handler,
            $name,
        );
    }
}
