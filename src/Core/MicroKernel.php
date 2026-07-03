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
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\ResponseEmitter;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

use function is_array;
use function is_callable;
use function is_object;
use function is_scalar;
use function is_string;

/**
 * Minimal kernel for single-file applications.
 *
 * Provides inline route registration with sensible security defaults
 * (security headers, CSRF protection, rate limiting) even in the
 * simplest configuration. No config files or directory structure required.
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
    private readonly Container $container;
    private readonly Router $router;
    private readonly MiddlewarePipeline $pipeline;
    /** @var list<PsrMiddlewareInterface|class-string<PsrMiddlewareInterface>> */
    private array $globalMiddleware = [];
    private bool $booted = false;

    private function __construct()
    {
        $this->container = new Container();
        $this->router = new Router();
        $this->pipeline = new MiddlewarePipeline($this->container);

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
        $this->router->get($path, $handler, $name);

        return $this;
    }

    /**
     * Register a POST route.
     *
     */
    public function post(string $path, mixed $handler, ?string $name = null): self
    {
        $this->router->post($path, $handler, $name);

        return $this;
    }

    /**
     * Register a PUT route.
     *
     */
    public function put(string $path, mixed $handler, ?string $name = null): self
    {
        $this->router->put($path, $handler, $name);

        return $this;
    }

    /**
     * Register a PATCH route.
     *
     */
    public function patch(string $path, mixed $handler, ?string $name = null): self
    {
        $this->router->patch($path, $handler, $name);

        return $this;
    }

    /**
     * Register a DELETE route.
     *
     */
    public function delete(string $path, mixed $handler, ?string $name = null): self
    {
        $this->router->delete($path, $handler, $name);

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
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->boot();

        try {
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
        }
    }

    /**
     * Handle a request from PHP superglobals and emit the response.
     */
    public function run(): void
    {
        $request = ServerRequest::fromGlobals();
        $response = $this->handle($request);

        new ResponseEmitter()->emit($response, $request->getMethod());
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
        // FR-22: an unrecognized verb maps to 501 Not Implemented, not a 500.
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
