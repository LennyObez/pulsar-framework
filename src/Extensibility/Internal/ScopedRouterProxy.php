<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use NoDiscard;
use Override;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Exception\CapabilityDeniedException;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Http\Method;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;

use function preg_match;
use function rtrim;
use function str_starts_with;

/**
 * Capability-gated proxy for RouterInterface.
 *
 * Enforces route namespace constraints based on trust tier:
 * - Core: full access, no proxy needed
 * - Verified: no prefix, but wildcards rejected
 * - Community: routes prefixed with /ext/{extension-name}/
 * - Untrusted: route registration denied
 *
 * Read-only operations (match, routes, count) always delegate.
 *
 * @internal Not part of the public API
 */
final readonly class ScopedRouterProxy implements RouterInterface
{
    private string $prefix;

    public function __construct(
        private RouterInterface $inner,
        private TrustTier $tier,
        private string $extensionName,
        private CapabilityPolicy $policy,
    ) {
        $this->prefix = '/ext/' . $this->extensionName;
    }

    #[Override]
    public function add(Route $route): self
    {
        $this->assertCanRegisterRoute($route->path);
        $route = $this->applyPrefix($route);
        $this->inner->add($route);

        return $this;
    }

    #[Override]
    public function get(string $path, mixed $handler, ?string $name = null): self
    {
        $this->assertCanRegisterRoute($path);
        $this->inner->get($this->prefixPath($path), $handler, $name);

        return $this;
    }

    #[Override]
    public function post(string $path, mixed $handler, ?string $name = null): self
    {
        $this->assertCanRegisterRoute($path);
        $this->inner->post($this->prefixPath($path), $handler, $name);

        return $this;
    }

    #[Override]
    public function put(string $path, mixed $handler, ?string $name = null): self
    {
        $this->assertCanRegisterRoute($path);
        $this->inner->put($this->prefixPath($path), $handler, $name);

        return $this;
    }

    #[Override]
    public function patch(string $path, mixed $handler, ?string $name = null): self
    {
        $this->assertCanRegisterRoute($path);
        $this->inner->patch($this->prefixPath($path), $handler, $name);

        return $this;
    }

    #[Override]
    public function delete(string $path, mixed $handler, ?string $name = null): self
    {
        $this->assertCanRegisterRoute($path);
        $this->inner->delete($this->prefixPath($path), $handler, $name);

        return $this;
    }

    #[Override]
    public function any(string $path, mixed $handler, ?string $name = null): self
    {
        $this->assertCanRegisterRoute($path);
        $this->inner->any($this->prefixPath($path), $handler, $name);

        return $this;
    }

    #[Override]
    public function group(string $prefix, callable $callback): self
    {
        $this->assertCanRegisterRoute($prefix);
        $this->inner->group($this->prefixPath($prefix), $callback);

        return $this;
    }

    #[Override]
    public function match(Method $method, string $path, ?string $host = null): MatchedRoute
    {
        return $this->inner->match($method, $path, $host);
    }

    #[Override]
    #[NoDiscard]
    public function routes(): array
    {
        return $this->inner->routes();
    }

    #[Override]
    public function count(): int
    {
        return $this->inner->count();
    }

    private function assertCanRegisterRoute(string $path): void
    {
        // Check basic route registration capability
        if (!$this->policy->allows($this->tier, ExtensionCapability::RouteRegister)) {
            throw CapabilityDeniedException::forCapability($this->tier, ExtensionCapability::RouteRegister);
        }

        // Verified tier: reject wildcard routes
        if ($this->tier === TrustTier::Verified && $this->isWildcardRoute($path)) {
            throw CapabilityDeniedException::forCapability($this->tier, ExtensionCapability::RouteRegisterGlobal);
        }
    }

    private function prefixPath(string $path): string
    {
        if ($this->tier->atLeast(TrustTier::Verified)) {
            return $path;
        }

        // Community and Untrusted: prefix with /ext/{extension-name}
        $path = rtrim($path, '/');

        if ($path === '' || $path === '/') {
            return $this->prefix;
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $this->prefix . $path;
    }

    private function applyPrefix(Route $route): Route
    {
        if ($this->tier->atLeast(TrustTier::Verified)) {
            return $route;
        }

        return new Route(
            methods: $route->methods,
            path: $this->prefixPath($route->path),
            handler: $route->handler,
            name: $route->name,
            attributes: $route->attributes,
            middleware: $route->middleware,
            constraints: $route->constraints,
            host: $route->host,
        );
    }

    private function isWildcardRoute(string $path): bool
    {
        // Catch-all wildcard: route that is just a single parameter segment
        // e.g., /{any}, /{path}, /{slug}
        return (bool) preg_match('#^/?\{[a-zA-Z_][a-zA-Z0-9_]*}$#', $path);
    }
}
