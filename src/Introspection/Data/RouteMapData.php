<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Data;

use function array_map;

use Pulsar\Api\Api;

/**
 * Complete route map for the application.
 */
#[Api(since: '1.0.0')]
final readonly class RouteMapData
{
    /**
     * @param list<RouteEntry> $routes
     */
    public function __construct(
        public array $routes = [],
    ) {}

    /**
     * @return array{routes: list<array{methods: list<string>, path: string, handler: string, name: string|null, middleware: list<string>}>}
     */
    public function toArray(): array
    {
        return [
            'routes' => array_map(
                static fn(RouteEntry $r): array => $r->toArray(),
                $this->routes,
            ),
        ];
    }
}
