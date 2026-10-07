<?php

declare(strict_types=1);

namespace Pulsar\Core;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Pulsar\Api\Api;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Http\Message\BodyTooLargeException;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\ResponseEmitter;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\RouteAccessRegistrar;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;
use Throwable;

use function error_log;
use function is_array;
use function is_callable;
use function is_object;
use function is_scalar;
use function is_string;
use function sprintf;

/**
 * Minimal kernel for single-file applications.
 *
 * Inline route registration with no config files and no directory structure.
 *
 * ## What it does not provide, said plainly
 *
 * There is no authentication, no authorization, and no per-route access
 * control here: no AuthManager, no middleware registry, no `auth` alias, and
 * therefore nothing a route could name a permission to. Every route registered
 * on a MicroKernel is reachable by any caller the application's own global
 * middleware lets through, and each one is registered as
 * {@see \Pulsar\Routing\RouteAccess::Public} to say so on the route rather
 * than leave it to be discovered. An application that needs a guarded route
 * needs the full {@see Kernel}.
 *
 * This docblock used to promise "sensible security defaults (security headers,
 * CSRF protection, rate limiting)". {@see MicroKernel::boot()} pipes exactly the
 * middleware the caller passed to {@see MicroKernel::use()} and nothing else, so
 * the promise described a pipeline that has never existed. A false claim about a
 * control is worse than an absent control, because it is the reason nobody looks.
 *
 * ```php
 * $app = MicroKernel::create();
 * $app->get('/hello/{name}', fn(ServerRequestInterface $r, string $name) => "Hello, $name!");
 * $app->run();
 * ```
 * @api
 */
#[Api(since: '1.0.0')]
final class MicroKernel
{
    /** Recorded on every MicroKernel route; see the class docblock. */
    private const string ACCESS_REASON = 'MicroKernel provides no authentication or per-route '
        . 'authorization, so every route it registers is reachable by any caller the '
        . 'application-supplied global middleware admits.';

    private readonly Container $container;
    private readonly Router $router;
    private readonly MiddlewarePipeline $pipeline;
    private readonly RouteAccessRegistrar $routes;
    /** @var list<PsrMiddlewareInterface|class-string<PsrMiddlewareInterface>> */
    private array $globalMiddleware = [];
    private bool $booted = false;

    private function __construct()
    {
        $this->container = new Container();
        $this->router = new Router();
        $this->pipeline = new MiddlewarePipeline($this->container);
        // A registry of its own, permanently empty: MicroKernel publishes no
        // middleware aliases, so RouteAccessRegistrar::authenticated() could
        // never find a guard here and would refuse to register a route that
        // claimed one. Public is the only declaration this kernel can honestly make.
        $this->routes = new RouteAccessRegistrar($this->router, new MiddlewareRegistry());

        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Router::class, $this->router);
    }

    /**
     * Create a new MicroKernel with security defaults.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Register a GET route.
     *
     */
    public function get(string $path, mixed $handler, ?string $name = null): self
    {
        $this->routes->publicRoute([Method::GET], $path, $handler, $name, self::ACCESS_REASON);

        return $this;
    }

    /**
     * Register a POST route.
     *
     */
    public function post(string $path, mixed $handler, ?string $name = null): self
    {
        $this->routes->publicRoute([Method::POST], $path, $handler, $name, self::ACCESS_REASON);

        return $this;
    }

    /**
     * Register a PUT route.
     *
     */
    public function put(string $path, mixed $handler, ?string $name = null): self
    {
        $this->routes->publicRoute([Method::PUT], $path, $handler, $name, self::ACCESS_REASON);

        return $this;
    }

    /**
     * Register a PATCH route.
     *
     */
    public function patch(string $path, mixed $handler, ?string $name = null): self
    {
        $this->routes->publicRoute([Method::PATCH], $path, $handler, $name, self::ACCESS_REASON);

        return $this;
    }

    /**
     * Register a DELETE route.
     *
     */
    public function delete(string $path, mixed $handler, ?string $name = null): self
    {
        $this->routes->publicRoute([Method::DELETE], $path, $handler, $name, self::ACCESS_REASON);

        return $this;
    }

    /**
     * Register a route group with a common prefix.
     */
    public function group(string $prefix, callable $callback): self
    {
        $this->router->group($prefix, $callback);

        return $this;
    }

    /**
     * Add global middleware.
     *
     * @param PsrMiddlewareInterface|class-string<PsrMiddlewareInterface> $middleware
     */
    public function use(PsrMiddlewareInterface|string $middleware): self
    {
        $this->globalMiddleware[] = $middleware;

        return $this;
    }

    /**
     * Register a service in the container.
     */
    public function bind(string $abstract, mixed $concrete): self
    {
        if ($concrete instanceof Closure) {
            $this->container->bind($abstract, $concrete);
        } elseif (is_object($concrete)) {
            $this->container->instance($abstract, $concrete);
        } elseif (is_string($concrete) && class_exists($concrete)) {
            $this->container->bind($abstract, $concrete);
        }

        return $this;
    }

    /**
     * Get the container instance.
     */
    public function container(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Handle a request and return a response.
     *
     * Returns a response for every input. Only RoutingException used to be
     * caught, so anything a handler or a middleware threw escaped to the SAPI
     * and was printed there with its class, message and absolute source path.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->boot();

            if ($this->pipeline->count() > 0) {
                return $this->pipeline->dispatch(
                    $request,
                    fn(ServerRequestInterface $req): ResponseInterface => $this->dispatch($req),
                );
            }

            return $this->dispatch($request);
        } catch (RoutingException $e) {
            return Response::json(
                ['error' => 'Not Found', 'message' => $e->getMessage()],
                404,
            );
        } catch (Throwable $e) {
            error_log(sprintf('[Pulsar] Unhandled %s: %s', $e::class, $e->getMessage()));

            return new ProductionRenderer()->response(ResponseStatus::InternalServerError);
        }
    }

    /**
     * Handle a request from PHP superglobals and emit the response.
     *
     * Guarded end to end: this is the outermost frame the framework controls,
     * and whatever escapes it is rendered by the SAPI with `display_errors`
     * deciding whether the client sees a stack trace.
     */
    public function run(): void
    {
        try {
            $request = ServerRequest::fromGlobals();
        } catch (Throwable $e) {
            // No request object means no pipeline; the page carries its own
            // security headers. A body over the cap used to reach the SAPI as
            // an uncaught BodyTooLargeException with a full trace.
            $this->emitPreRequestFailure($e);

            return;
        }

        $response = $this->handle($request);

        try {
            new ResponseEmitter()->emit($response, $request->getMethod());
        } catch (Throwable $e) {
            error_log('[Pulsar] Response emission failed: ' . $e->getMessage());
        }
    }

    private function emitPreRequestFailure(Throwable $e): void
    {
        error_log(sprintf('[Pulsar] Request construction failed (%s): %s', $e::class, $e->getMessage()));

        try {
            new ResponseEmitter()->emit($this->preRequestFailureResponse($e));
        } catch (Throwable $emitFailure) {
            error_log('[Pulsar] Pre-request error emission failed: ' . $emitFailure->getMessage());
        }
    }

    /**
     * A body over the cap is the one pre-request failure with an answer the
     * client can act on, and the ceiling is server policy rather than a secret.
     * Anything else that stops a request being parsed is a malformed request.
     */
    private function preRequestFailureResponse(Throwable $e): ResponseInterface
    {
        $status = $e instanceof BodyTooLargeException
            ? ResponseStatus::PayloadTooLarge
            : ResponseStatus::BadRequest;

        return new ProductionRenderer()->response($status);
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Apply global middleware
        foreach ($this->globalMiddleware as $middleware) {
            $this->pipeline->pipe($middleware);
        }

        $this->booted = true;
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        // An unrecognized verb maps to 501 Not Implemented, not a 500 (RFC 9110 §15.6.2).
        $method = \Pulsar\Http\Method::tryFrom($request->getMethod())
            ?? throw \Pulsar\Routing\RoutingException::notImplemented($request->getMethod());
        $path = $request->getUri()->getPath();
        $host = $request->getHeaderLine('Host');

        $matched = $this->router->match($method, $path, $host !== '' ? $host : null);

        // Add route params to request
        foreach ($matched->parameters as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        /** @var mixed $handler */
        $handler = $matched->getHandler();
        /** @var mixed $response */
        $response = null;

        if (is_callable($handler)) {
            /** @var mixed $response */
            $response = $handler($request, ...array_values($matched->parameters));
        } elseif (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0]) && is_string($handler[1])) {
            $class = $handler[0];
            $method = $handler[1];

            if (class_exists($class)) {
                /** @var mixed $controller */
                $controller = $this->container->has($class)
                    ? $this->container->get($class)
                    : new $class();
                if (is_object($controller) && is_callable([$controller, $method])) {
                    /** @var mixed $response */
                    $response = $controller->$method($request, ...array_values($matched->parameters));
                }
            }
        } elseif (is_string($handler) && class_exists($handler)) {
            /** @var mixed $controller */
            $controller = $this->container->has($handler)
                ? $this->container->get($handler)
                : new $handler();
            if (is_callable($controller)) {
                /** @var mixed $response */
                $response = $controller($request, ...array_values($matched->parameters));
            }
        }

        if ($response === null) {
            throw RoutingException::nonCallableHandler();
        }

        if (is_string($response)) {
            return Response::html($response);
        }

        if (is_array($response)) {
            /** @var array<string, mixed> $jsonData */
            $jsonData = $response;
            return Response::json($jsonData);
        }

        if ($response instanceof ResponseInterface) {
            return $response;
        }

        return Response::html(is_scalar($response) ? (string) $response : '');
    }
}
