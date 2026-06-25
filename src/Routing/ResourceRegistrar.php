<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Internal;
use Pulsar\Http\Method;

use function end;
use function explode;
use function str_contains;
use function str_ends_with;
use function substr;
use function trim;

/**
 * Builds RESTful resource route sets for the {@see Router}.
 *
 * Extracted from Router so the resource scaffolding (and its naive
 * pluralization) is a single responsibility, separate from registration and
 * matching. Pure: returns Route lists the Router then registers.
 */
#[Internal(reason: 'Resource route scaffolding; use Router::resource()/apiResource()')]
final class ResourceRegistrar
{
    /**
     * Build a full resource route set (7 routes): index, create, store, show,
     * edit, update, destroy.
     *
     * @param list<string> $middleware
     * @return list<Route>
     */
    public static function resourceRoutes(string $name, string $controller, array $middleware): array
    {
        $prefix = '/' . trim($name, '/');
        $paramSegment = '/{' . self::singularize($name) . '}';

        return [
            new Route([Method::GET, Method::HEAD], $prefix, [$controller, 'index'], $name . '.index', middleware: $middleware),
            new Route([Method::GET, Method::HEAD], $prefix . '/create', [$controller, 'create'], $name . '.create', middleware: $middleware),
            new Route([Method::POST], $prefix, [$controller, 'store'], $name . '.store', middleware: $middleware),
            new Route([Method::GET, Method::HEAD], $prefix . $paramSegment, [$controller, 'show'], $name . '.show', middleware: $middleware),
            new Route([Method::GET, Method::HEAD], $prefix . $paramSegment . '/edit', [$controller, 'edit'], $name . '.edit', middleware: $middleware),
            new Route([Method::PUT, Method::PATCH], $prefix . $paramSegment, [$controller, 'update'], $name . '.update', middleware: $middleware),
            new Route([Method::DELETE], $prefix . $paramSegment, [$controller, 'destroy'], $name . '.destroy', middleware: $middleware),
        ];
    }

    /**
     * Build an API resource route set (5 routes, no create/edit forms):
     * index, store, show, update, destroy.
     *
     * @param list<string> $middleware
     * @return list<Route>
     */
    public static function apiResourceRoutes(string $name, string $controller, array $middleware): array
    {
        $prefix = '/' . trim($name, '/');
        $paramSegment = '/{' . self::singularize($name) . '}';

        return [
            new Route([Method::GET, Method::HEAD], $prefix, [$controller, 'index'], $name . '.index', middleware: $middleware),
            new Route([Method::POST], $prefix, [$controller, 'store'], $name . '.store', middleware: $middleware),
            new Route([Method::GET, Method::HEAD], $prefix . $paramSegment, [$controller, 'show'], $name . '.show', middleware: $middleware),
            new Route([Method::PUT, Method::PATCH], $prefix . $paramSegment, [$controller, 'update'], $name . '.update', middleware: $middleware),
            new Route([Method::DELETE], $prefix . $paramSegment, [$controller, 'destroy'], $name . '.destroy', middleware: $middleware),
        ];
    }

    /**
     * Naive English pluralization: derive singular from plural resource name.
     *
     * Handles common suffixes: -ies -> -y, -ses/-xes/-zes/-shes/-ches -> drop
     * suffix, -s -> drop s. For irregular nouns, specify the parameter name via
     * route constraints.
     */
    public static function singularize(string $name): string
    {
        // Only take the last segment if nested (e.g. 'admin/photos' -> 'photos')
        if (str_contains($name, '/')) {
            $segments = explode('/', trim($name, '/'));
            $name = end($segments);
        }

        if (str_ends_with($name, 'ies')) {
            return substr($name, 0, -3) . 'y';
        }

        if (str_ends_with($name, 'ses') || str_ends_with($name, 'xes') || str_ends_with($name, 'zes')
            || str_ends_with($name, 'shes') || str_ends_with($name, 'ches')) {
            return substr($name, 0, -2);
        }

        if (str_ends_with($name, 's') && !str_ends_with($name, 'ss')) {
            return substr($name, 0, -1);
        }

        return $name;
    }
}
