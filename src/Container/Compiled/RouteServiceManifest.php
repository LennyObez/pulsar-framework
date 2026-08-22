<?php

declare(strict_types=1);

namespace Pulsar\Container\Compiled;

use NoDiscard;
use Pulsar\Api\Api;

use function array_key_exists;
use function array_unique;
use function array_values;
use function count;

/**
 * Per-route service manifest for lazy service resolution.
 *
 * At build time, the container compiler analyzes which services each route's
 * controller requires. At runtime, the container consults this manifest to
 * resolve only the services needed for the matched route instead of the full
 * dependency graph.
 * @api
 */
#[Api(since: '1.0.0')]
final class RouteServiceManifest
{
    /**
     * Map of route name/pattern → list of service IDs required.
     *
     * @var array<string, list<string>>
     */
    private array $routeServices;

    /**
     * @param array<string, list<string>> $routeServices
     */
    public function __construct(array $routeServices = [])
    {
        $this->routeServices = $routeServices;
    }

    /**
     * Get the service IDs required by a specific route.
     *
     * @return list<string> Service IDs needed for the route, empty if no manifest entry
     */
    #[NoDiscard]
    public function servicesForRoute(string $routeKey): array
    {
        return $this->routeServices[$routeKey] ?? [];
    }

    /**
     * Check whether a manifest entry exists for the given route.
     */
    #[NoDiscard]
    public function hasRoute(string $routeKey): bool
    {
        return array_key_exists($routeKey, $this->routeServices);
    }

    /**
     * Register the services required by a route.
     *
     * @param list<string> $serviceIds
     */
    public function registerRoute(string $routeKey, array $serviceIds): void
    {
        $this->routeServices[$routeKey] = array_values(array_unique($serviceIds));
    }

    /**
     * Get all registered route keys.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function routeKeys(): array
    {
        return array_keys($this->routeServices);
    }

    /**
     * Get the number of registered routes.
     */
    #[NoDiscard]
    public function count(): int
    {
        return count($this->routeServices);
    }

    /**
     * Export the manifest as a serializable array.
     *
     * @return array<string, list<string>>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return $this->routeServices;
    }

    /**
     * Create a manifest from a serialized array (e.g. from a cached file).
     *
     * @param array<string, list<string>> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self($data);
    }
}
