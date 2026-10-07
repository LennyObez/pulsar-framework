<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use NoDiscard;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Pulsar\Api\Api;
use Pulsar\Routing\MatchedRoute;

/**
 * A middleware that acts on the route being dispatched, and is handed that
 * route by the kernel instead of reading it off the request.
 *
 * ## Why the request cannot be the channel
 *
 * The kernel writes the matched route to the `_route` request attribute, and
 * every frame between routing and the handler can rewrite it: route-level
 * middleware, anything piped into the {@see PostRoutingPipeline}, anything
 * holding the request at all. The kernel itself never reads the attribute back
 * — it dispatches the {@see MatchedRoute} it closed over in
 * {@see \Pulsar\Core\Kernel::dispatchRoute()} — so a rewritten attribute does
 * not change WHICH handler runs. It only changes what the middleware reading it
 * believes is running.
 *
 * That divergence is a forgery primitive wherever a middleware derives
 * authority from the route. {@see \Pulsar\Routing\Binding\ModelBindingMiddleware}
 * reads the binding plan, the authorization opt-out, the public-route
 * declaration and the parameters to bind from it, and hands the result to the
 * handler as vetted, sealed arguments. Fed another route's declarations while
 * the kernel serves the real one, it produces a seal for a route that was never
 * dispatched — a value the argument resolver then treats as authorized on the
 * real handler's entity-typed parameter.
 *
 * ## What this interface does instead
 *
 * There is no second value to compare and no mismatch to detect. The pipeline
 * that the kernel hands the dispatched route to rebinds every middleware
 * implementing this interface, for that dispatch only, before the chain is
 * built — see {@see MiddlewarePipeline::dispatch()}. A middleware bound this way
 * has one route: the one the kernel is about to invoke a handler for. Rewriting
 * `_route` reaches nothing it consults.
 *
 * ## Binding returns a new instance, and that is the point
 *
 * Implementations must return a copy rather than mutate themselves. Middleware
 * are resolved once and reused for the process lifetime — the same instance
 * serves every request, and under a persistent worker that interleaves
 * Fiber-suspended requests it serves several at once. A route stored on the
 * shared instance would be some other request's route. The per-dispatch copy is
 * the whole isolation mechanism, which is why {@see forDispatchedRoute()} is
 * `#[\NoDiscard]`: dropping the return value silently leaves the unbound
 * instance in the chain, and an unbound instance does nothing.
 *
 * ## Why this is public surface
 *
 * The framework is not the only thing that needs it. Any extension middleware
 * whose decision belongs to the route — an authorization layer, a rate limiter
 * keyed on the route rather than the path, an audit frame naming the endpoint —
 * has exactly the problem described above, and a protocol only the framework
 * could implement would leave every extension reading the rewritable attribute
 * while the framework read the safe channel. That is privileged built-in
 * access, which this codebase does not grant itself.
 *
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface DispatchedRouteAwareInterface extends PsrMiddlewareInterface
{
    /**
     * Return a copy of this middleware bound to the route being dispatched.
     *
     * Called by the pipeline once per dispatch, before the middleware chain is
     * built. The receiver must not retain the route on itself.
     */
    #[NoDiscard]
    public function forDispatchedRoute(MatchedRoute $route): self;
}
