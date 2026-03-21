<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\Method;

use function array_filter;
use function in_array;
use function is_string;
use function str_contains;
use function trim;

use const ARRAY_FILTER_USE_KEY;

/**
 * Represents a single route definition.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Route
{
    /**
     * Pre-compiled regex pattern for parameterized routes.
     *
     * Null for static routes (no `{` in path). Computed eagerly in
     * the constructor so that `matchesPath()` never recomputes it.
     */
    public ?string $compiledPattern;

    /**
     * F2.13 (host parity): pre-compiled regex pattern for parameterised
     * hosts. Null for static / unset hosts. Computed eagerly so
     * `matchesHost()` never recomputes it on the dispatch hot path.
     */
    public ?string $compiledHostPattern;

    /**
     * @param list<Method> $methods Allowed HTTP methods
     * @param string $path The route path pattern
     * @param mixed $handler The route handler
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
    ) {
        $normalizedPath = '/' . trim($this->path, '/');
        $this->compiledPattern = str_contains($normalizedPath, '{')
            ? $this->pathToPattern($normalizedPath)
            : null;

        $this->compiledHostPattern = $this->host !== null && str_contains($this->host, '{')
            ? $this->hostToPattern($this->host)
            : null;
    }

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
     * @return array<string, string>|null
     */
    public function matchesPath(string $path): ?array
    {
        $requestPath = '/' . trim($path, '/');

        // Static route: no parameters
        if ($this->compiledPattern === null) {
            $routePath = '/' . trim($this->path, '/');
            return $routePath === $requestPath ? [] : null;
        }

        // Use pre-compiled regex pattern
        if (preg_match($this->compiledPattern, $requestPath, $matches)) {
            return $this->extractNamedParameters($matches);
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

        // Convert /{param?} to (?:/(?P<param>CONSTRAINT))?: the preceding slash
        // becomes optional together with the parameter so that /blog/{page?}
        // matches both /blog and /blog/2.
        // Note: preg_quote escapes ? to \?, so we match the escaped form.
        $replaced = preg_replace_callback(
            '#/\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\?}#',
            static function (array $matches) use ($constraints): string {
                $name = $matches[1];
                $regex = $constraints[$name] ?? '[^/]+';
                return '(?:/(?P<' . $name . '>' . $regex . '))?';
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
     * @return array<string, string>|null
     */
    public function matchesHost(string $host): ?array
    {
        if ($this->host === null) {
            return [];
        }

        // Exact match (no parameters)
        if ($this->compiledHostPattern === null) {
            return strtolower($this->host) === strtolower($host) ? [] : null;
        }

        if (preg_match($this->compiledHostPattern, $host, $matches)) {
            return $this->extractNamedParameters($matches);
        }

        return null;
    }

    /**
     * Pre-compile a parameterised host pattern.
     */
    private function hostToPattern(string $host): string
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
     * Create a GET route.
     *
     * @param mixed $handler
     */
    #[NoDiscard]
    public static function get(string $path, mixed $handler, ?string $name = null): self
    {
        return new self([Method::GET, Method::HEAD], $path, $handler, $name);
    }

    /**
     * Create a POST route.
     *
     * @param mixed $handler
     */
    #[NoDiscard]
    public static function post(string $path, mixed $handler, ?string $name = null): self
    {
        return new self([Method::POST], $path, $handler, $name);
    }

    /**
     * Create a PUT route.
     *
     * @param mixed $handler
     */
    #[NoDiscard]
    public static function put(string $path, mixed $handler, ?string $name = null): self
    {
        return new self([Method::PUT], $path, $handler, $name);
    }

    /**
     * Create a PATCH route.
     *
     * @param mixed $handler
     */
    #[NoDiscard]
    public static function patch(string $path, mixed $handler, ?string $name = null): self
    {
        return new self([Method::PATCH], $path, $handler, $name);
    }

    /**
     * Create a DELETE route.
     *
     * @param mixed $handler
     */
    #[NoDiscard]
    public static function delete(string $path, mixed $handler, ?string $name = null): self
    {
        return new self([Method::DELETE], $path, $handler, $name);
    }

    /**
     * Create a route matching any method.
     *
     * @param mixed $handler
     */
    #[NoDiscard]
    public static function any(string $path, mixed $handler, ?string $name = null): self
    {
        return new self(
            [Method::GET, Method::HEAD, Method::POST, Method::PUT, Method::PATCH, Method::DELETE, Method::OPTIONS],
            $path,
            $handler,
            $name,
        );
    }

    /**
     * Extract named parameters from preg_match results.
     *
     * Filters out numeric keys (capture group indices) and returns
     * only the named capture groups as string-keyed values.
     *
     * @param array<int|string, string> $matches
     *
     * @psalm-suppress InvalidReturnType: Psalm cannot narrow key types through ARRAY_FILTER_USE_KEY
     *
     * @return array<string, string>
     */
    private function extractNamedParameters(array $matches): array
    {
        /** @psalm-suppress InvalidReturnStatement */
        return array_filter($matches, static fn(int|string $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
    }
}
