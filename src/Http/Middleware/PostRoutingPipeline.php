<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use NoDiscard;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Routing\MatchedRoute;

/**
 * The kernel's second, post-routing middleware pipeline.
 *
 * The global pipeline runs BEFORE routing: its innermost handler is the
 * kernel's dispatch, and `_route` is only attached once dispatch is already
 * running. Middleware that must observe the matched route — anything reading
 * `_route`, `_route_name` or a route attribute — is therefore unreachable from
 * the global pipeline and can only be piped here.
 *
 * Observing the route is not the same as deriving authority from it. `_route`
 * is an ordinary request attribute, and this pipeline hands every middleware it
 * runs the request the previous frame returned — so a middleware piped or
 * PREPENDED here can hand the next one any route it likes, while the kernel
 * goes on serving the one it matched. A middleware whose decisions must be the
 * dispatched route's implements {@see DispatchedRouteAwareInterface} and is
 * given that route by {@see dispatch()}, which is the only channel no frame in
 * this pipeline sits on.
 *
 * POSITION IS A CORRECTNESS CONSTRAINT, NOT A PREFERENCE. The kernel dispatches
 * this pipeline INSIDE route-level middleware, as the innermost frame before the
 * handler. That is the only position which is simultaneously after routing (so
 * `_route` exists) and after every frame that can authenticate: the globally
 * piped `AuthenticationMiddleware`, and the route-level `'auth'` alias. Moving it
 * outside route middleware would let a route carrying `['auth']` run this
 * pipeline before authenticating; under a regulated preset every such route would
 * start returning 401. The trade is that route middleware cannot observe what
 * this pipeline produces — a route middleware cannot read `_bound_models`.
 *
 * "After every frame that can authenticate" is the accurate claim, and it is
 * weaker than "after authentication": only the `'auth'` alias resolves an
 * identity. The globally piped `AuthenticationMiddleware` attaches a lazy
 * SecurityContext and an anonymous placeholder. Middleware piped here that needs
 * to know the caller must therefore resolve through that SecurityContext rather
 * than trust the `_identity` attribute to be filled in.
 *
 * Empty by default and free when empty: the kernel tests {@see isEmpty()} and
 * calls the handler directly, so an application that registers nothing here pays
 * no pipeline construction and adds no stack frame.
 */
#[Internal(reason: 'Kernel-owned; middleware that must observe the matched route')]
final class PostRoutingPipeline implements MiddlewarePipelineInterface
{
    private readonly MiddlewarePipeline $inner;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->inner = new MiddlewarePipeline($container);
    }

    /**
     * @param PsrMiddlewareInterface|class-string<PsrMiddlewareInterface> $middleware
     */
    #[Override]
    public function pipe(PsrMiddlewareInterface|string $middleware): MiddlewarePipelineInterface
    {
        $this->inner->pipe($middleware);

        return $this;
    }

    /**
     * @param PsrMiddlewareInterface|class-string<PsrMiddlewareInterface> $middleware
     */
    #[Override]
    public function prepend(PsrMiddlewareInterface|string $middleware): MiddlewarePipelineInterface
    {
        $this->inner->prepend($middleware);

        return $this;
    }

    /**
     * Whether nothing has been piped. Read on the hot path of every dispatched
     * request, so it delegates to the inner stack rather than tracking a second
     * flag that could drift out of sync with a snapshot restore.
     */
    #[NoDiscard]
    public function isEmpty(): bool
    {
        return $this->inner->isEmpty();
    }

    /**
     * Run $handler through the post-routing stack.
     *
     * $dispatchedRoute is the route the kernel is about to invoke a handler for.
     * It is passed as an argument rather than left to be read off `_route`
     * because the attribute is rewritable by everything that runs between
     * routing and here — including anything an extension prepends to this very
     * pipeline — while the kernel dispatches the route it closed over and never
     * consults the attribute again. Handing it down here is what gives a
     * {@see DispatchedRouteAwareInterface} middleware the route being SERVED
     * rather than the route the request claims.
     *
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    #[NoDiscard]
    public function dispatch(
        ServerRequestInterface $request,
        callable $handler,
        ?MatchedRoute $dispatchedRoute = null,
    ): ResponseInterface {
        return $this->inner->dispatch($request, $handler, $dispatchedRoute);
    }

    /**
     * Capture the stack for the kernel boot/shutdown lifecycle.
     *
     * @return list<PsrMiddlewareInterface|class-string<PsrMiddlewareInterface>>
     */
    #[NoDiscard]
    public function snapshot(): array
    {
        return $this->inner->snapshot();
    }

    /**
     * @param list<PsrMiddlewareInterface|class-string<PsrMiddlewareInterface>> $snapshot
     */
    public function restoreFromSnapshot(array $snapshot): void
    {
        $this->inner->restoreFromSnapshot($snapshot);
    }
}
