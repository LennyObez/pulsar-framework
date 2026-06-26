<?php

declare(strict_types=1);

namespace Pulsar\Core\Boot;

use Pulsar\Api\Internal;
use Pulsar\Cache\CachedRoute;
use Pulsar\Cache\RouteHandlerType;
use Pulsar\Routing\Route;

/**
 * Reconstructs {@see Route} objects from cached route DTOs.
 *
 * This conversion lives in the composition root (extracted from {@see \Pulsar\Core\Kernel})
 * so that {@see \Pulsar\Routing\Router} never imports Cache-internal types
 * ({@see CachedRoute}, {@see RouteHandlerType}). Pure, boot-time only.
 */
#[Internal]
final class CachedRouteReconstructor
{
    /**
     * @param list<CachedRoute> $cachedRoutes
     * @return list<Route>
     */
    public static function reconstruct(array $cachedRoutes): array
    {
        $routes = [];

        foreach ($cachedRoutes as $cached) {
            /** @var class-string $resolvable */
            $resolvable = $cached->handler->resolvable;
            $handler = match ($cached->handler->type) {
                RouteHandlerType::Invokable => $resolvable,
                RouteHandlerType::Method => [$resolvable, $cached->handler->method ?? '__invoke'],
            };

            $routes[] = new Route(
                methods: $cached->methods,
                path: $cached->path,
                handler: $handler,
                name: $cached->name,
                attributes: $cached->attributes,
                middleware: $cached->middleware,
                constraints: $cached->constraints,
                host: $cached->host,
            );
        }

        return $routes;
    }
}
