<?php

declare(strict_types=1);

namespace Pulsar\Core;

use Error;
use JsonException;
use Pulsar\Api\Internal;
use Pulsar\Cache\CachedRoute;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Cache\RouteHandlerType;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use Pulsar\Core\Wiring\AuthWiring;
use Pulsar\Core\Wiring\ConfigWiring;
use Pulsar\Core\Wiring\DatabaseWiring;
use Pulsar\Core\Wiring\DeployWiring;
use Pulsar\Core\Wiring\DiagnosticsWiring;
use Pulsar\Core\Wiring\ErrorTrackingWiring;
use Pulsar\Core\Wiring\ExceptionHandlerWiring;
use Pulsar\Core\Wiring\FeatureFlagWiring;
use Pulsar\Core\Wiring\IntegrityWiring;
use Pulsar\Core\Wiring\IntrospectionWiring;
use Pulsar\Core\Wiring\LoggingWiring;
use Pulsar\Core\Wiring\MetricsWiring;
use Pulsar\Core\Wiring\QueueWiring;
use Pulsar\Core\Wiring\RequestContextWiring;
use Pulsar\Core\Wiring\ResilienceWiring;
use Pulsar\Core\Wiring\RuntimeWiring;
use Pulsar\Core\Wiring\SchedulerWiring;
use Pulsar\Core\Wiring\SecurityWiring;
use Pulsar\Core\Wiring\SupervisorWiring;
use Pulsar\Core\Wiring\TenancyWiring;
use Pulsar\Core\Wiring\TracingWiring;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\FeatureFlag\Exception\FeatureFlagException;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewarePipelineInterface;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseEmitter;
use Pulsar\Http\RouteContext;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;
use Pulsar\Routing\RoutingException;
use Random\Engine\Secure;
use Random\Randomizer;
use ReflectionException;
use SodiumException;
use Throwable;

use function is_array;
use function is_callable;
use function is_string;

/**
 * Pulsar Kernel
 *
 * The kernel is responsible for bootstrapping the application,
 * managing the lifecycle, and orchestrating the request/response cycle.
 *
 * Boot pipeline order:
 * Config -> Logger -> Tracer -> Metrics -> RequestContext -> ErrorTracker
 * -> ExceptionHandler -> Security -> Auth -> Database -> Tenancy -> FeatureFlags
 * -> Scheduler -> Resilience -> Queue -> Supervisor -> Integrity -> Deploy
 * -> DiagnosticsRoute -> Extensions (register -> preBoot -> boot -> postBoot)
 */
#[Internal]
final class Kernel implements KernelInterface
{
    public private(set) bool $booted = false;
    private ContainerInterface $container;
    private Router $router;
    private MiddlewarePipeline $middleware;
    private MiddlewareRegistry $middlewareRegistry;
    private ?ExtensionBootstrap $extensionBootstrap;
    private ?ConfigManager $configManager;
    private ?ExceptionHandler $exceptionHandler = null;
    private ?RouteContext $routeContext = null;
    private ?BootProfile $bootProfile = null;
    private ?MetricRegistry $metricsRegistry = null;

    public function __construct(
        ?ContainerInterface $container = null,
        ?Router $router = null,
        ?ExtensionBootstrap $extensionBootstrap = null,
        ?ConfigManager $configManager = null,
    ) {
        $this->container = $container ?? new Container();
        $this->router = $router ?? new Router();
        $this->middleware = new MiddlewarePipeline($this->container);
        $this->middlewareRegistry = new MiddlewareRegistry();
        $this->extensionBootstrap = $extensionBootstrap;
        $this->configManager = $configManager;

        // Register core services in container
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(RouterInterface::class, $this->router);
        $this->container->instance(MiddlewarePipelineInterface::class, $this->middleware);
        $this->container->instance(MiddlewareRegistry::class, $this->middlewareRegistry);
        $this->container->instance(self::class, $this);
        $this->container->instance(KernelInterface::class, $this);

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
     * 2. Logger creation
     * 3. Tracer creation (TracingMiddleware as outermost global middleware)
     * 4. Metrics creation (MetricsMiddleware as inner global middleware)
     * 4b. RequestContext creation (RequestContextMiddleware after metrics)
     * 5. Error tracker creation
     * 6. Exception handler creation
     * 7. Security services creation
     * 8. Auth services creation
     * 9. Database services creation (if config/database.php exists)
     * 10. Tenancy services creation (if config/tenancy.php exists)
     * 11. Feature flag services creation (if config/features.php exists)
     * 12. Scheduler services creation (if config/scheduler.php exists)
     * 13. Resilience services creation (if config/resilience.php exists)
     * 14. Diagnostics route registration (debug mode only)
     * 15. Extension register phase
     * 16. Extension preBoot phase (PreBootExtensionInterface)
     * 17. Extension boot phase
     * 18. Extension postBoot phase (PostBootExtensionInterface)
     *
     * @throws ContainerException If a container error occurs during bootstrap
     * @throws NotFoundException If a required binding is not found during bootstrap
     * @throws ReflectionException If class reflection fails during autowiring
     * @throws FeatureFlagException If flag storage fails during boot
     * @throws JsonException If flag serialization fails during boot
     * @throws SodiumException If a sodium cryptographic operation fails during boot
     * @throws RoutingException If the router is locked in strict cache mode
     * @throws ExtensionException If extension registration or boot fails
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $bootStart = hrtime(true);

        // Cache-aware boot: attempt to load config from FrameworkCache
        $cacheLoaded = false;
        $routesCached = false;

        $cacheStart = hrtime(true);

        if ($this->configManager !== null && $this->container->has(FrameworkCache::class)) {
            /** @var FrameworkCache $frameworkCache */
            $frameworkCache = $this->container->get(FrameworkCache::class);
            $configPath = $this->configManager->configPath();

            if ($configPath !== null) {
                $cached = $frameworkCache->load($configPath);

                if ($cached !== null && $cached['config'] !== null) {
                    $cacheLoaded = $this->configManager->loadFromCache($cached['config']);
                }

                if ($cached !== null && $cached['containerHints'] !== null) {
                    $this->container->setResolutionHints($cached['containerHints']);
                }

                // Apply cached routes to the router
                if ($cached !== null && $cached['routes'] !== null && $cached['routes'] !== []) {
                    $this->router->loadRoutes($this->reconstructCachedRoutes($cached['routes']));
                    $routesCached = true;

                    if ($cached['manifest']->strict) {
                        $this->router->lock();
                    }
                }
            }
        }

        $cacheLoadUs = (int) ((hrtime(true) - $cacheStart) / 1000);

        // Register shared Randomizer (CSPRNG) singleton
        $randomizer = new Randomizer(new Secure());
        $this->container->instance(Randomizer::class, $randomizer);

        // Config phase: load config, create services via wiring classes
        $configStart = hrtime(true);

        if ($this->configManager !== null) {
            if (!$cacheLoaded) {
                $this->configManager->load();
            }

            $wirings = [
                new ConfigWiring(),
                new LoggingWiring(),
                new TracingWiring(),
                new MetricsWiring(),
                new RequestContextWiring(),
                new ErrorTrackingWiring(),
                new ExceptionHandlerWiring(),
                new SecurityWiring(),
                new AuthWiring(),
                new DatabaseWiring(),
                new TenancyWiring(),
                new FeatureFlagWiring(),
                new SchedulerWiring(),
                new ResilienceWiring(),
                new QueueWiring(),
                new SupervisorWiring(),
                new IntegrityWiring(),
                new DeployWiring(),
                new RuntimeWiring(),
                new DiagnosticsWiring(),
                new IntrospectionWiring(),
            ];

            foreach ($wirings as $wiring) {
                $wiring->wire($this->container, $this->configManager, $this->middleware, $this->middlewareRegistry, $this->router);
            }

            // Fetch ExceptionHandler from container (registered by ExceptionHandlerWiring)
            if ($this->container->has(ExceptionHandler::class)) {
                /** @var ExceptionHandler $handler */
                $handler = $this->container->get(ExceptionHandler::class);
                $this->exceptionHandler = $handler;
            }

            // Fetch RouteContext from container (registered by TracingWiring or MetricsWiring)
            if ($this->container->has(RouteContext::class)) {
                /** @var RouteContext $routeContext */
                $routeContext = $this->container->get(RouteContext::class);
                $this->routeContext = $routeContext;
            }
        }

        $configUs = (int) ((hrtime(true) - $configStart) / 1000);

        // Extension register phase (all extensions)
        $extRegisterStart = hrtime(true);
        $this->extensionBootstrap?->register($this->container);
        $extensionRegisterUs = (int) ((hrtime(true) - $extRegisterStart) / 1000);

        // Extension boot phase (all extensions — includes preBoot, boot, postBoot)
        $extBootStart = hrtime(true);
        $this->extensionBootstrap?->boot($this->container, $this->router);
        $extensionBootUs = (int) ((hrtime(true) - $extBootStart) / 1000);

        $this->booted = true;

        // Cache MetricRegistry reference for hot-path dispatch timing
        if ($this->container->has(MetricRegistry::class)) {
            /** @var MetricRegistry $registry */
            $registry = $this->container->get(MetricRegistry::class);
            $this->metricsRegistry = $registry;
        }

        $totalUs = (int) ((hrtime(true) - $bootStart) / 1000);

        $this->bootProfile = new BootProfile(
            totalUs: $totalUs,
            cacheLoadUs: $cacheLoadUs,
            configUs: $configUs,
            extensionRegisterUs: $extensionRegisterUs,
            extensionBootUs: $extensionBootUs,
            cacheHit: $cacheLoaded,
            routesCached: $routesCached,
        );

        // Emit boot duration metric if MetricRegistry is available
        $this->metricsRegistry?->gauge(
            'pulsar_boot_duration_us',
            'Total kernel boot duration in microseconds',
        )->set((float) $totalUs);
    }

    /**
     * Get the boot profile (available after boot completes).
     */
    public function bootProfile(): ?BootProfile
    {
        return $this->bootProfile;
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
    public function configManager(): ?ConfigManagerInterface
    {
        return $this->configManager;
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
    public function router(): RouterInterface
    {
        return $this->router;
    }

    /**
     * Get the middleware registry.
     */
    public function middlewareRegistry(): MiddlewareRegistry
    {
        return $this->middlewareRegistry;
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
     *
     * @throws Throwable If no exception handler is registered or re-thrown after handling fails
     */
    public function handle(Request $request): Response
    {
        $this->boot();

        // Reset route context for this request (worker reuse safety)
        $this->routeContext?->reset();

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
     *
     * @throws Throwable If no exception handler is registered or re-thrown after handling fails
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
     *
     * @throws RoutingException When no route matches, method is not allowed, or handler is invalid
     * @throws ContainerException If a container error occurs resolving a controller
     * @throws NotFoundException If a controller binding is not found in the container
     * @throws Error If a controller class cannot be instantiated
     */
    private function dispatchRoute(Request $request): Response
    {
        $host = $request->header('Host');

        if ($this->metricsRegistry !== null) {
            $matchStart = hrtime(true);
            $matched = $this->router->match($request->method, $request->path, $host);
            $matchUs = (int) ((hrtime(true) - $matchStart) / 1000);

            $this->metricsRegistry->histogram(
                'pulsar_route_match_us',
                'Route matching duration in microseconds',
                [10.0, 25.0, 50.0, 100.0, 250.0, 500.0, 1000.0],
            )->observe((float) $matchUs);
        } else {
            $matched = $this->router->match($request->method, $request->path, $host);
        }

        // Populate RouteContext for observability middleware (metrics/tracing)
        if ($this->routeContext !== null) {
            $this->routeContext->pattern = $matched->route->path;
            $this->routeContext->name = $matched->getName();
        }

        // Add route parameters to request attributes
        $request = $this->addRouteAttributesToRequest($request, $matched);

        // Apply route-specific middleware, resolving names through the registry
        if ($matched->getMiddleware() !== []) {
            $pipeline = new MiddlewarePipeline($this->container);
            foreach ($matched->getMiddleware() as $middleware) {
                if (is_string($middleware)) {
                    $resolved = $this->middlewareRegistry->resolve($middleware);
                    foreach ($resolved as $m) {
                        $pipeline->pipe($m);
                    }
                } else {
                    /** @var MiddlewareInterface $middleware */
                    $pipeline->pipe($middleware);
                }
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
        return $request->withAttributes([
            '_route' => $matched,
            '_route_name' => $matched->getName(),
            ...$matched->parameters,
        ]);
    }

    /**
     * Invoke the route handler.
     *
     * @throws Throwable If the handler throws or is invalid
     * @throws ContainerException If a container error occurs resolving a controller
     * @throws NotFoundException If a controller binding is not found in the container
     * @throws Error If a controller class cannot be instantiated
     */
    private function invokeHandler(Request $request, MatchedRoute $matched): Response
    {
        $handler = $matched->getHandler();

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
                throw RoutingException::invalidHandler($handler);
            }
        } else {
            throw RoutingException::nonCallableHandler();
        }

        // Convert string responses to Response objects
        if (is_string($response)) {
            return Response::html($response);
        }

        if (!$response instanceof Response) {
            throw RoutingException::unexpectedReturnType(get_debug_type($response));
        }

        return $response;
    }

    /**
     * Resolve a controller instance from the container or instantiate directly.
     *
     * @param class-string $class
     *
     * @throws ContainerException If a container error occurs during resolution
     * @throws NotFoundException If the resolved binding is not found
     * @throws ReflectionException If class reflection fails during autowiring
     * @throws Error If the class cannot be instantiated
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
     * Reconstruct Route objects from cached route DTOs.
     *
     * This conversion lives in the composition root so that Router
     * never imports Cache-internal types (CachedRoute, RouteHandlerType).
     *
     * @param list<CachedRoute> $cachedRoutes
     * @return list<Route>
     */
    private function reconstructCachedRoutes(array $cachedRoutes): array
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
