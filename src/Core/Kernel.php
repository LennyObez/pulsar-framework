<?php

declare(strict_types=1);

namespace Pulsar\Core;

use function is_array;
use function is_callable;
use function is_string;

use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseEmitter;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;
use RuntimeException;

use function sprintf;

/**
 * Pulsar Kernel
 *
 * The kernel is responsible for bootstrapping the application,
 * managing the lifecycle, and orchestrating the request/response cycle.
 */
final class Kernel
{
    private bool $booted = false;
    private ContainerInterface $container;
    private Router $router;
    private MiddlewarePipeline $middleware;
    private ?ExtensionBootstrap $extensionBootstrap;

    public function __construct(
        ?ContainerInterface $container = null,
        ?Router $router = null,
        ?ExtensionBootstrap $extensionBootstrap = null,
    ) {
        $this->container = $container ?? new Container();
        $this->router = $router ?? new Router();
        $this->middleware = new MiddlewarePipeline($this->container);
        $this->extensionBootstrap = $extensionBootstrap;

        // Register core services in container
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(self::class, $this);

        // Register extension bootstrap if provided
        if ($this->extensionBootstrap !== null) {
            $this->container->instance(ExtensionBootstrap::class, $this->extensionBootstrap);
        }
    }

    /**
     * Boot the kernel.
     *
     * This method initializes all core services and prepares
     * the application for handling requests. Extension lifecycle:
     * 1. Register phase: All extensions register services
     * 2. Boot phase: All extensions boot (routes, etc.)
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Extension register phase (all extensions)
        $this->extensionBootstrap?->register($this->container);

        // Extension boot phase (all extensions)
        $this->extensionBootstrap?->boot($this->container, $this->router);

        $this->booted = true;
    }

    /**
     * Get the extension bootstrap instance.
     */
    public function extensionBootstrap(): ?ExtensionBootstrap
    {
        return $this->extensionBootstrap;
    }

    /**
     * Check if the kernel has been booted.
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Get the container instance.
     */
    public function container(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Get the router instance.
     */
    public function router(): Router
    {
        return $this->router;
    }

    /**
     * Add global middleware.
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface> $middleware
     */
    public function addMiddleware(MiddlewareInterface|string $middleware): self
    {
        $this->middleware->pipe($middleware);
        return $this;
    }

    /**
     * Handle an HTTP request and return a response.
     */
    public function handle(Request $request): Response
    {
        $this->boot();

        return $this->middleware->handle($request, fn(Request $req) => $this->dispatchRoute($req));
    }

    /**
     * Handle a request from PHP superglobals and emit the response.
     */
    public function run(): void
    {
        $request = Request::fromGlobals();
        $response = $this->handle($request);

        $emitter = new ResponseEmitter();
        $emitter->emit($response);
    }

    /**
     * Dispatch the request to the matched route handler.
     */
    private function dispatchRoute(Request $request): Response
    {
        try {
            $matched = $this->router->match($request->method, $request->path);

            // Add route parameters to request attributes
            $request = $this->addRouteAttributesToRequest($request, $matched);

            // Apply route-specific middleware
            if ($matched->getMiddleware() !== []) {
                $pipeline = new MiddlewarePipeline($this->container);
                foreach ($matched->getMiddleware() as $middleware) {
                    /** @var MiddlewareInterface|class-string<MiddlewareInterface> $middleware */
                    $pipeline->pipe($middleware);
                }
                return $pipeline->handle($request, fn(Request $req) => $this->invokeHandler($req, $matched));
            }

            return $this->invokeHandler($request, $matched);
        } catch (RoutingException $e) {
            return $this->handleRoutingException($e);
        }
    }

    /**
     * Add matched route information to the request.
     */
    private function addRouteAttributesToRequest(Request $request, MatchedRoute $matched): Request
    {
        $request = $request->withAttribute('_route', $matched);
        $request = $request->withAttribute('_route_name', $matched->getName());

        foreach ($matched->parameters as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $request;
    }

    /**
     * Invoke the route handler.
     */
    private function invokeHandler(Request $request, MatchedRoute $matched): Response
    {
        $handler = $matched->getHandler();
        $response = null;

        if (is_callable($handler)) {
            $response = $handler($request, $matched->parameters);
        } elseif (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = $this->resolveController($class);
            $response = $controller->$method($request, $matched->parameters);
        } elseif (is_string($handler) && class_exists($handler)) {
            $controller = $this->resolveController($handler);
            if (method_exists($controller, '__invoke')) {
                $response = $controller($request, $matched->parameters);
            } else {
                throw new RuntimeException(sprintf(
                    'Controller "%s" must be callable or specify a method',
                    $handler,
                ));
            }
        } else {
            throw new RuntimeException('Invalid route handler');
        }

        // Convert string responses to Response objects
        if (is_string($response)) {
            return Response::html($response);
        }

        if (!$response instanceof Response) {
            throw new RuntimeException(sprintf(
                'Handler must return a Response or string, got %s',
                get_debug_type($response),
            ));
        }

        return $response;
    }

    /**
     * Resolve a controller instance from the container or instantiate directly.
     *
     * @param class-string $class
     */
    private function resolveController(string $class): object
    {
        if ($this->container->has($class)) {
            /** @var object */
            return $this->container->get($class);
        }

        return new $class();
    }

    /**
     * Handle routing exceptions.
     */
    private function handleRoutingException(RoutingException $exception): Response
    {
        if ($exception->isNotFound()) {
            return Response::html(
                '<h1>404 Not Found</h1><p>The requested resource was not found.</p>',
                ResponseStatus::NotFound,
            );
        }

        if ($exception->isMethodNotAllowed()) {
            return Response::html(
                '<h1>405 Method Not Allowed</h1><p>' . htmlspecialchars($exception->getMessage()) . '</p>',
                ResponseStatus::MethodNotAllowed,
            )->withHeader('Allow', $exception->getAllowHeader());
        }

        throw $exception;
    }

    /**
     * Shutdown the kernel.
     *
     * Performs cleanup and releases resources.
     */
    public function shutdown(): void
    {
        $this->booted = false;
    }
}
