<?php

declare(strict_types=1);

namespace Pulsar\Core\Boot;

use Pulsar\Api\Internal;
use Pulsar\Cache\CachedBinding;
use Pulsar\Cache\CachedRoute;
use Pulsar\Cache\RouteHandlerType;
use Pulsar\Routing\Binding\ExplicitBinding;
use Pulsar\Routing\Binding\ModelBindingException;
use Pulsar\Routing\Route;

use function is_array;

/**
 * Translates between the route table and the DTOs that can be cached.
 *
 * The translation lives in the composition root (extracted from
 * {@see \Pulsar\Core\Kernel}) so that neither module has to import the other's
 * private types: {@see \Pulsar\Routing\Router} never sees {@see CachedRoute} or
 * {@see CachedBinding}, and `Pulsar\Cache` never sees {@see ExplicitBinding},
 * which is `#[Internal]` because an application declares a binding by calling
 * `Router::model()` rather than by constructing one. Pure, boot-time only.
 *
 * Both halves of the route table pass through here, and they have to: a
 * cached-route boot skips the project route files, so the `Router::model()`
 * calls those files make happen exactly once — at `pulsar optimize` time — and
 * the declarations they produce reach production only by being carried.
 */
#[Internal]
final class CachedRouteReconstructor
{
    /**
     * Rebuild the cached route table.
     *
     * The parameter is `mixed` on purpose. {@see \Pulsar\Cache\FrameworkCacheInterface}
     * is stable API an application may implement itself, and `load()` is a
     * contract rather than a guarantee: an implementation that stores its own
     * payload, or restores one written by an older version of the framework, can
     * hand back anything at all under the `routes` key. Trusting the declared
     * shape meant a foreign payload reached property reads on plain arrays —
     * `Attempt to read property "resolvable" on array`, then a `TypeError` deep
     * inside `boot()` — which is a 500 on EVERY request of an application that
     * was working a moment earlier, from a cache that is supposed to be an
     * optimization.
     *
     * So a payload this method cannot read is answered the same way a
     * self-contradictory declaration is: `null`, meaning "do not serve this
     * table", and the caller boots from the project route files instead. The
     * cost is one cold boot, and the alternative is a boot that cannot happen at
     * all.
     *
     * @return list<Route>|null Null when the payload is not a list of {@see CachedRoute}
     */
    public static function reconstruct(mixed $cachedRoutes): ?array
    {
        if (!is_array($cachedRoutes)) {
            return null;
        }

        $routes = [];

        foreach ($cachedRoutes as $cached) {
            if (!$cached instanceof CachedRoute) {
                return null;
            }

            $resolvable = $cached->handler->resolvable;

            // A stored route naming no handler is not one to serve. The
            // `instanceof` above establishes the TYPE of every field — the DTO's
            // properties are typed — so what is left to check is emptiness,
            // which the type system does not cover and a hand-built payload can
            // carry.
            if ($resolvable === '') {
                return null;
            }

            /** @var class-string $resolvable */
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

    /**
     * Rebuild the declarations `Router::model()` made before the cache was written.
     *
     * Every one goes back through {@see ExplicitBinding}'s constructor, which is
     * the sole judge of whether a scope and a relation agree. That is not
     * ceremony: `unserialize()` does not run constructors, so the stored blob is
     * the one place a `Contained` binding could arrive without the relation it
     * resolves through — and a null relation reaching
     * {@see \Pulsar\Routing\Binding\BindingResolver} is a child resolved through
     * nothing.
     *
     * A refusal returns null rather than throwing, because the caller has a
     * better answer than an aborted boot: discard the cached route table
     * entirely and read the project route files, where the declarations are made
     * for real. Nothing that writes a cache can produce this — `Router::model()`
     * validates on the way in — so the branch exists for a corrupted or
     * hand-built payload, and its cost is one cold boot.
     *
     * `null` in means the payload did not carry the declarations at all — a
     * {@see \Pulsar\Cache\FrameworkCacheInterface} implementation older than the
     * `bindings` key, which the kernel must still be able to boot behind. It
     * gets the same answer as a contradiction, and for the same reason: what
     * the route table binds, and how, is not something this boot can state, so
     * the cached table is not the one to serve. The distinction between "absent"
     * and "self-contradictory" would change nothing the caller does.
     *
     * The parameter is `mixed` for the reason {@see reconstruct()} gives: a
     * payload from an implementation the framework did not write can carry
     * anything under this key, and reading it as the declared shape turned that
     * into an uncatchable `TypeError` inside `boot()` rather than a cold boot.
     * An unreadable payload gets the same answer as an absent one, because the
     * caller does the same thing with both.
     *
     * @return list<ExplicitBinding>|null Null when the declarations are absent, unreadable, or self-contradictory
     */
    public static function reconstructBindings(mixed $cachedBindings): ?array
    {
        if (!is_array($cachedBindings)) {
            return null;
        }

        $bindings = [];

        foreach ($cachedBindings as $cached) {
            if (!$cached instanceof CachedBinding) {
                return null;
            }

            try {
                $bindings[] = new ExplicitBinding(
                    $cached->parameter,
                    $cached->modelClass,
                    $cached->resolverClass,
                    $cached->scope,
                    $cached->parentRelation,
                );
            } catch (ModelBindingException) {
                return null;
            }
        }

        return $bindings;
    }

    /**
     * Convert the router's declarations into the form the cache stores.
     *
     * The write half of {@see reconstructBindings()}, here for the same reason:
     * the conversion has to happen in a composition root, and `pulsar optimize`
     * is one.
     *
     * @param list<ExplicitBinding> $bindings
     * @return list<CachedBinding>
     */
    public static function forCache(array $bindings): array
    {
        $cached = [];

        foreach ($bindings as $binding) {
            $cached[] = new CachedBinding(
                $binding->parameter,
                $binding->modelClass,
                $binding->resolverClass,
                $binding->scope,
                $binding->parentRelation,
            );
        }

        return $cached;
    }
}
