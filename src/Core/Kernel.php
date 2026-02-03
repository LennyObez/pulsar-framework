<?php

declare(strict_types=1);

namespace Pulsar\Core;

use function is_array;
use function is_callable;
use function is_string;

use Psr\Log\LoggerInterface;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\Environment;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\ErrorHandling\DevelopmentRenderer;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseEmitter;
use Pulsar\Observability\Log\Logger;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Router;
use RuntimeException;

use function sprintf;

use Throwable;

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
    private ?ConfigManager $configManager;
    private ?ExceptionHandler $exceptionHandler = null;

    public function __construct(
        ?ContainerInterface $container = null,
        ?Router $router = null,
        ?ExtensionBootstrap $extensionBootstrap = null,
        ?ConfigManager $configManager = null,
    ) {
        $this->container = $container ?? new Container();
        $this->router = $router ?? new Router();
        $this->middleware = new MiddlewarePipeline($this->container);
        $this->extensionBootstrap = $extensionBootstrap;
        $this->configManager = $configManager;

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
     * the application for handling requests. Boot pipeline:
     * 1. Config loading (if ConfigManager provided)
     * 2. Extension register phase
     * 3. Extension boot phase
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Config phase: load config, create logger, create exception handler
        if ($this->configManager !== null) {
            $this->configManager->load();
            $this->registerConfigServices();
            $this->createLogger();
            $this->createExceptionHandler();
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
     * Get the config manager instance.
     */
    public function configManager(): ?ConfigManager
    {
        return $this->configManager;
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

        try {
            return $this->middleware->handle($request, fn(Request $req) => $this->dispatchRoute($req));
        } catch (Throwable $e) {
            if ($this->exceptionHandler !== null) {
                return $this->exceptionHandler->handle($e, $request);
            }

            throw $e;
        }
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
     * Register config DTOs and related services in the container.
     */
    private function registerConfigServices(): void
    {
        /** @var ConfigManager $configManager Already checked non-null before calling */
        $configManager = $this->configManager;
        $repository = $configManager->repository();
        $environment = $configManager->environment();

        $this->container->instance(ConfigManager::class, $configManager);
        $this->container->instance(ConfigRepository::class, $repository);
        $this->container->instance(Environment::class, $environment);

        /** @var AppConfig $appConfig */
        $appConfig = $repository->get(AppConfig::class);
        $this->container->instance(AppConfig::class, $appConfig);

        /** @var ObservabilityConfig $observabilityConfig */
        $observabilityConfig = $repository->get(ObservabilityConfig::class);
        $this->container->instance(ObservabilityConfig::class, $observabilityConfig);
    }

    /**
     * Create the logger from config and register in the container.
     */
    private function createLogger(): void
    {
        /** @var ConfigManager $configManager */
        $configManager = $this->configManager;

        /** @var ObservabilityConfig $observabilityConfig */
        $observabilityConfig = $configManager->repository()->get(ObservabilityConfig::class);
        $logger = Logger::fromConfig($observabilityConfig);

        $this->container->instance(LoggerInterface::class, $logger);
        $this->container->instance(Logger::class, $logger);
    }

    /**
     * Create the exception handler from config and register in the container.
     */
    private function createExceptionHandler(): void
    {
        /** @var ConfigManager $configManager */
        $configManager = $this->configManager;

        /** @var AppConfig $appConfig */
        $appConfig = $configManager->repository()->get(AppConfig::class);

        $renderer = $appConfig->debug
            ? new DevelopmentRenderer()
            : new ProductionRenderer();

        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        /** @var LoggerInterface|null $logger */
        $this->exceptionHandler = new ExceptionHandler($renderer, $logger);
        $this->container->instance(ExceptionHandler::class, $this->exceptionHandler);
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
