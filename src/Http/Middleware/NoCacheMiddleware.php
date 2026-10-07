<?php

declare(strict_types=1);

namespace Pulsar\Http\Middleware;

use LogicException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Api;
use Pulsar\Http\Attribute\NoCacheResponse;
use Pulsar\Routing\MatchedRoute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

use function class_exists;
use function explode;
use function is_array;
use function is_object;
use function is_string;
use function str_contains;

/**
 * Applies anti-caching headers to responses from handlers annotated with
 * #[NoCacheResponse]. Prevents sensitive data (PII, financial records)
 * from being cached by browsers or intermediate proxies.
 *
 * ## Where the handler comes from
 *
 * The dispatched route, handed over by the pipeline through
 * {@see DispatchedRouteAwareInterface}. Not a request attribute: this middleware
 * used to read `_controller`, which nothing in the framework has ever written,
 * so it added no header to any response any application ever served while
 * presenting itself — alias, attribute and all — as an active control. And even
 * a real attribute would be the wrong channel, because every frame between
 * routing and here can rewrite one: a rewritten route would decide whether a
 * statement of account is cacheable, for a route that is not being served.
 *
 * ## Where it must be attached
 *
 * Anywhere the kernel hands the pipeline the matched route — on the route
 * itself (`new Route(..., middleware: ['no-cache'])`, scoping the reflection to
 * the sensitive routes) or in the {@see PostRoutingPipeline} (one registration, and every
 * `#[NoCacheResponse]` in the application is enforced). Piped into the GLOBAL
 * pipeline it runs before routing and can never learn which handler serves the
 * request, so it refuses to run at all rather than return the response
 * unprotected: see {@see process()}.
 *
 * Attaching this middleware is not by itself a declaration that the route is
 * sensitive. It enforces `#[NoCacheResponse]` on the handler, and a route whose
 * handler carries no such declaration passes through untouched — which is what
 * lets a single post-routing registration serve a whole application without
 * marking every response `no-store`.
 *
 * The attribute lookup is cached per `ClassName::methodName` key. Route
 * handlers are resolved once at route registration and never change at
 * runtime, so the cache is safe for the entire process lifetime and
 * eliminates the per-request `ReflectionMethod` allocation.
 * @api
 */
#[Api(since: '1.0.0')]
final class NoCacheMiddleware implements DispatchedRouteAwareInterface, MiddlewareInterface
{
    /**
     * Per-handler lookup cache: `"Class::method"` => has-attribute bool.
     *
     * @var array<string, bool>
     */
    private static array $attributeCache = [];

    /**
     * The route being dispatched, or null when nothing bound one.
     *
     * Never stored on the shared instance — {@see forDispatchedRoute()} returns
     * a copy, because middleware are resolved once and reused for the process
     * lifetime and a route left on `$this` would be some other request's route.
     */
    private ?MatchedRoute $dispatchedRoute = null;

    #[Override]
    public function forDispatchedRoute(MatchedRoute $route): self
    {
        $bound = clone $this;
        $bound->dispatchedRoute = $route;

        return $bound;
    }

    /**
     * @throws LogicException When no pipeline bound a dispatched route, which
     *                        means this middleware is wired where it can never
     *                        see one.
     */
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $this->dispatchedRoute;

        if ($route === null) {
            // A wiring error, and one that cannot be worked around at runtime:
            // there is no route here and there never will be on this path. The
            // alternative — hand the response back unprotected — is the failure
            // this class was found in, an operator believing sensitive responses
            // carry `no-store` while every one of them was cacheable.
            throw new LogicException(
                'NoCacheMiddleware ran without a dispatched route. It enforces the '
                . '#[NoCacheResponse] declaration on the route handler, so it has to be '
                . 'attached where the kernel hands the pipeline the matched route: on the '
                . "route (new Route(..., middleware: ['no-cache'])) or in the PostRoutingPipeline. Piped "
                . 'into the global pipeline it runs before routing, can never learn which '
                . 'handler serves the request, and would leave every sensitive response '
                . 'cacheable while appearing to protect it.',
            );
        }

        $response = $handler->handle($request);

        if (!$this->handlerDeclaresNoCache($route->getHandler())) {
            return $response;
        }

        return $response
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    /**
     * Whether the route handler declares #[NoCacheResponse].
     *
     * Mirrors the handler shapes the kernel itself dispatches — see
     * {@see \Pulsar\Core\Kernel::invokeHandler()} — so a declaration is found
     * wherever a route can legally put one.
     */
    private function handlerDeclaresNoCache(mixed $handler): bool
    {
        // [Controller::class, 'method'], the ordinary declaration.
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0]) && is_string($handler[1])) {
            return $this->declaresNoCache($handler[0], $handler[1]);
        }

        if (is_string($handler)) {
            // "Controller::method".
            if (str_contains($handler, '::')) {
                $parts = explode('::', $handler, 2);

                return isset($parts[1]) && $this->declaresNoCache($parts[0], $parts[1]);
            }

            // An invokable controller named by class-string.
            return $this->declaresNoCache($handler, '__invoke');
        }

        // An already-constructed invokable controller. A Closure lands here too
        // and answers false, correctly: #[NoCacheResponse] targets methods and
        // classes, so a closure handler has nowhere to carry one and there is
        // nothing for this middleware to enforce on it.
        if (is_object($handler)) {
            return $this->declaresNoCache($handler::class, '__invoke');
        }

        return false;
    }

    private function declaresNoCache(string $class, string $method): bool
    {
        $cacheKey = $class . '::' . $method;

        if (isset(self::$attributeCache[$cacheKey])) {
            return self::$attributeCache[$cacheKey];
        }

        try {
            $ref = new ReflectionMethod($class, $method);

            if ($ref->getAttributes(NoCacheResponse::class) !== []) {
                return self::$attributeCache[$cacheKey] = true;
            }

            // A class-level attribute covers every action the controller serves.
            return self::$attributeCache[$cacheKey] =
                $ref->getDeclaringClass()->getAttributes(NoCacheResponse::class) !== [];
        } catch (ReflectionException) {
            // The method does not exist — a handler the kernel would refuse to
            // invoke. The class may still exist and still declare the attribute,
            // and answering "not sensitive" for a class that says it is would be
            // the wrong way to be wrong.
            return self::$attributeCache[$cacheKey] = class_exists($class)
                && new ReflectionClass($class)->getAttributes(NoCacheResponse::class) !== [];
        }
    }
}
