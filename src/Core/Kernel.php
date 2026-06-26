<?php

declare(strict_types=1);

namespace Pulsar\Core;

use Error;
use JsonException;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Pulsar\Api\Internal;
use Pulsar\Api\OpenApi\OpenApiWiring;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\AdvancedContainerInterface;
use Pulsar\Container\Compiler\Pass\AutoTagPass;
use Pulsar\Container\Compiler\Pass\ValidateDecoratorPass;
use Pulsar\Container\Compiler\Pass\ValidateLifetimesPass;
use Pulsar\Container\Compiler\PassRunner;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use Pulsar\Core\Boot\BuildArtifactVerifier;
use Pulsar\Core\Boot\CachedRouteReconstructor;
use Pulsar\Core\Boot\ExtensionDiscovery;
use Pulsar\Core\Boot\ExtensionViewPathRegistrar;
use Pulsar\Core\Boot\ProjectRouteLoader;
use Pulsar\Core\Controller\ControllerResolverInterface;
use Pulsar\Core\Controller\ReflectionControllerResolver;
use Pulsar\Core\Event\TerminateEvent;
use Pulsar\Core\Wiring\AntiSpamWiring;
use Pulsar\Core\Wiring\ApiWiring;
use Pulsar\Core\Wiring\AssetWiring;
use Pulsar\Core\Wiring\AuthWiring;
use Pulsar\Core\Wiring\CacheWiring;
use Pulsar\Core\Wiring\CloudWiring;
use Pulsar\Core\Wiring\ConfigWiring;
use Pulsar\Core\Wiring\DatabaseWiring;
use Pulsar\Core\Wiring\DeployWiring;
use Pulsar\Core\Wiring\DiagnosticsWiring;
use Pulsar\Core\Wiring\ErrorTrackingWiring;
use Pulsar\Core\Wiring\EventWiring;
use Pulsar\Core\Wiring\ExceptionHandlerWiring;
use Pulsar\Core\Wiring\FeatureFlagWiring;
use Pulsar\Core\Wiring\I18nWiring;
use Pulsar\Core\Wiring\IntegrityWiring;
use Pulsar\Core\Wiring\IntrospectionWiring;
use Pulsar\Core\Wiring\LoggingWiring;
use Pulsar\Core\Wiring\MailWiring;
use Pulsar\Core\Wiring\MetricsWiring;
use Pulsar\Core\Wiring\NotificationWiring;
use Pulsar\Core\Wiring\QueueWiring;
use Pulsar\Core\Wiring\RequestContextWiring;
use Pulsar\Core\Wiring\ResilienceWiring;
use Pulsar\Core\Wiring\RuntimeWiring;
use Pulsar\Core\Wiring\SchedulerWiring;
use Pulsar\Core\Wiring\SecurityWiring;
use Pulsar\Core\Wiring\ServiceDiscoveryWiring;
use Pulsar\Core\Wiring\StorageWiring;
use Pulsar\Core\Wiring\SupervisorWiring;
use Pulsar\Core\Wiring\TenancyWiring;
use Pulsar\Core\Wiring\TracingWiring;
use Pulsar\Core\Wiring\ViewWiring;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\FeatureFlag\Exception\FeatureFlagException;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\CallableRequestHandler;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewarePipelineInterface;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\ResponseEmitter;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\RouteContext;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;
use Pulsar\Routing\RoutingException;
use Random\Engine\Secure;
use Random\Randomizer;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use SodiumException;
use Throwable;

use function is_array;
use function is_callable;
use function is_string;
use function is_subclass_of;
use function sprintf;

/**
 * Pulsar Kernel
 *
 * The kernel is responsible for bootstrapping the application,
 * managing the lifecycle, and orchestrating the request/response cycle.
 *
 * Boot pipeline order:
 * Config -> Logger -> Tracer -> Security -> Metrics -> RequestContext -> ErrorTracker
 * -> ExceptionHandler -> I18n -> Auth -> Database -> Tenancy -> FeatureFlags
 * -> Scheduler -> Resilience -> Queue -> Supervisor -> ServiceDiscovery
 * -> Integrity -> Deploy -> DiagnosticsRoute
 * -> Extensions (register -> preBoot -> boot -> postBoot)
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
    private bool $dispatchHandlerSet = false;

    /** @var array<string, bool> */
    private array $handlerUsesArrayParams = [];

    /** @var array<string, bool> */
    private array $handlerWantsRequest = [];

    /** @var array<string, list<array{name: string, hasDefault: bool, default: mixed}>> */
    private array $handlerParamMap = [];

    private readonly ControllerResolverInterface $controllerResolver;

    public function __construct(
        ?ContainerInterface $container = null,
        ?Router $router = null,
        ?ExtensionBootstrap $extensionBootstrap = null,
        ?ConfigManager $configManager = null,
        ?ControllerResolverInterface $controllerResolver = null,
    ) {
        $this->container = $container ?? new Container();
        $this->controllerResolver = $controllerResolver ?? new ReflectionControllerResolver($this->container);
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
     * 4. Security services creation (SecurityHeaders + CORS middleware)
     * 5. Metrics creation (MetricsMiddleware as inner global middleware)
     * 5b. RequestContext creation (RequestContextMiddleware after metrics)
     * 6. Error tracker creation
     * 7. Exception handler creation
     * 7b. I18n services creation (LocaleMiddleware)
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

        // When a strict route cache is loaded, the cached routes are
        // authoritative: boot-time route registration (e.g. AssetWiring) is
        // skipped so it cannot duplicate cached routes or hit the locked router.
        $strictRouteCache = false;

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

                if ($cached !== null && $cached['manifest']->strict) {
                    $strictRouteCache = true;
                }

                // Apply cached routes to the router
                if ($cached !== null && $cached['routes'] !== null && $cached['routes'] !== []) {
                    $this->router->loadRoutes(CachedRouteReconstructor::reconstruct($cached['routes']));
                    $routesCached = true;

                    if ($strictRouteCache) {
                        $this->router->lock();
                    }
                }
            }
        }

        $cacheLoadUs = (int) ((hrtime(true) - $cacheStart) / 1000);

        // Build artifact verification (production mode)
        BuildArtifactVerifier::verify($this->container, $this->configManager);

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
                new I18nWiring(),
                new LoggingWiring(),
                new TracingWiring(),
                new SecurityWiring(),
                new MetricsWiring(),
                new RequestContextWiring(),
                new EventWiring(),
                new ErrorTrackingWiring(),
                new ExceptionHandlerWiring(),
                new AuthWiring(),
                new DatabaseWiring(),
                new TenancyWiring(),
                new FeatureFlagWiring(),
                new SchedulerWiring(),
                new ResilienceWiring(),
                new QueueWiring(),
                new CacheWiring(),
                new AntiSpamWiring(),
                new MailWiring(),
                new NotificationWiring(),
                new StorageWiring(),
                new CloudWiring(),
                new ServiceDiscoveryWiring(),
                new ApiWiring(),
                new OpenApiWiring(),
                new SupervisorWiring(),
                new IntegrityWiring(),
                new DeployWiring(),
                new RuntimeWiring(),
                new DiagnosticsWiring(),
                new IntrospectionWiring(),
                new ViewWiring(),
            ];

            // Asset routes are registered at boot only when not running from a
            // strict route cache; otherwise they are already in the cache and
            // re-registering them would duplicate routes or hit the locked router.
            if (!$strictRouteCache) {
                $wirings[] = new AssetWiring();
            }

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

        // Load project route files (routes/web.php, routes/api.php)
        if (!$routesCached) {
            ProjectRouteLoader::load($this->configManager, $this->router, $this->container);
        }

        // Auto-discover extensions when no bootstrap was provided but a
        // config manager exists (indicating a real project context, not a
        // bare kernel in tests). Scans getcwd()/extensions for pulsar.json
        // manifests so HTTP entry points work without explicit bootstrap.
        if ($this->extensionBootstrap === null && $this->configManager !== null) {
            $bootstrap = ExtensionDiscovery::discover($this->configManager);

            if ($bootstrap !== null) {
                $this->extensionBootstrap = $bootstrap;
                $this->container->instance(ExtensionBootstrap::class, $bootstrap);
            }
        }

        // Extension register phase (all extensions)
        $extRegisterStart = hrtime(true);
        $this->extensionBootstrap?->register($this->container);
        $extensionRegisterUs = (int) ((hrtime(true) - $extRegisterStart) / 1000);

        // Compiler pass phase (skip when cache is loaded; definitions are already processed)
        $compilerPassStart = hrtime(true);

        if (!$cacheLoaded && $this->container instanceof AdvancedContainerInterface) {
            $passRunner = new PassRunner();
            $passRunner->addPass(new AutoTagPass(), 100);
            $passRunner->addPass(new ValidateLifetimesPass(), -100);
            $passRunner->addPass(new ValidateDecoratorPass(), -100);
            $this->container->processCompilerPasses($passRunner);
        }

        $compilerPassUs = (int) ((hrtime(true) - $compilerPassStart) / 1000);

        // Import/export registry: singleton available for extension postBoot registration
        $importExportRegistry = new \Pulsar\ImportExport\ImportExportRegistry();
        $this->container->instance(\Pulsar\ImportExport\ImportExportRegistry::class, $importExportRegistry);

        // Extension boot phase (all extensions; includes preBoot, boot, postBoot)
        $extBootStart = hrtime(true);
        $this->extensionBootstrap?->boot($this->container, $this->router);
        $extensionBootUs = (int) ((hrtime(true) - $extBootStart) / 1000);

        // After extensions boot, add their view paths to the template engine.
        // Extensions may provide Pulse templates under resources/views/ (e.g., cms::public.pages.page).
        ExtensionViewPathRegistrar::register($this->container, $this->extensionBootstrap);

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
            compilerPassPhaseUs: $compilerPassUs,
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
     * F2.18: refuses to mutate the middleware pipeline after the
     * kernel has booted. The pipeline is cached after the first
     * `handle()` call so a post-boot `addMiddleware()` would
     * silently take effect only on a few requests (those that
     * happen to invalidate the cache for unrelated reasons) and
     * stay invisible on the rest — a near-impossible bug to
     * diagnose under load. Throw early instead.
     *
     * @param PsrMiddlewareInterface|class-string<PsrMiddlewareInterface> $middleware
     *
     * @throws LogicException When called after `boot()` has run.
     */
    public function addMiddleware(PsrMiddlewareInterface|string $middleware): self
    {
        if ($this->booted) {
            // F2.18: a programming error, not a runtime
            // condition — caller registered middleware in the
            // wrong phase of the lifecycle. LogicException
            // is the right base class.
            throw new LogicException(
                'addMiddleware() cannot be called after the kernel has booted; '
                . 'register all middleware before the first handle() invocation.',
            );
        }

        $this->middleware->pipe($middleware);
        return $this;
    }

    /**
     * Handle an HTTP request and return a response.
     *
     * @throws Throwable If no exception handler is registered or re-thrown after handling fails
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->boot();

        // Set the dispatch handler once (cached by the pipeline for subsequent requests)
        if ($this->middleware->count() > 0 && !$this->dispatchHandlerSet) {
            $this->middleware->setHandler(new CallableRequestHandler(
                fn(ServerRequestInterface $req): ResponseInterface => $this->dispatchRoute($req),
            ));
            $this->dispatchHandlerSet = true;
        }

        // Reset route context for this request (worker reuse safety)
        $this->routeContext?->reset();

        try {
            if ($this->dispatchHandlerSet) {
                return $this->middleware->handle($request);
            }

            // No global middleware: dispatch directly
            return $this->dispatchRoute($request);
        } catch (Throwable $e) {
            if ($this->exceptionHandler !== null) {
                return $this->exceptionHandler->handle($e, $request);
            }

            // F4.11: previously a missing exceptionHandler re-threw the
            // exception, letting the SAPI emit a default error page
            // with file paths + stack trace — a leak vector when an
            // application boots before its production exception
            // handler has been wired. Fall back to a minimal
            // ProductionRenderer so the response is a generic 500
            // with no internals exposed.
            return $this->renderFallbackError($e, $request);
        }
    }

    /**
     * F4.11: minimum-leak fallback when no `ExceptionHandler` is
     * registered. ProductionRenderer emits a generic 5xx page with
     * neither stack trace nor request internals. The caller is
     * expected to wire a real handler in normal app boot — this
     * branch only protects pre-bootstrap and misconfigured paths.
     */
    private function renderFallbackError(Throwable $e, ServerRequestInterface $request): ResponseInterface
    {
        $renderer = new ProductionRenderer();
        $status = match (true) {
            $e instanceof RoutingException && $e->isNotFound() => ResponseStatus::NotFound,
            $e instanceof RoutingException && $e->isMethodNotAllowed() => ResponseStatus::MethodNotAllowed,
            default => ResponseStatus::InternalServerError,
        };

        $response = Response::html(
            $renderer->render($e, $request, $status),
            $status->value,
        );

        // RFC 9110 §15.5.6: a 405 response must advertise the permitted methods.
        if ($e instanceof RoutingException && $e->isMethodNotAllowed()) {
            $response = $response->withHeader('Allow', $e->getAllowHeader());
        }

        return $response;
    }

    /**
     * Handle a request from PHP superglobals and emit the response.
     *
     * @throws Throwable If no exception handler is registered or re-thrown after handling fails
     */
    public function run(): void
    {
        $request = ServerRequest::fromGlobals();
        $response = $this->handle($request);

        new ResponseEmitter()->emit($response);

        $this->terminate($request, $response);
    }

    /**
     * Dispatch the request to the matched route handler.
     *
     * @throws RoutingException When no route matches, method is not allowed, or handler is invalid
     * @throws ContainerException If a container error occurs resolving a controller
     * @throws NotFoundException If a controller binding is not found in the container
     * @throws Error If a controller class cannot be instantiated
     */
    private function dispatchRoute(ServerRequestInterface $request): ResponseInterface
    {
        $host = $request->getHeaderLine('Host');
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $methodEnum = Method::from($method);

        if ($this->metricsRegistry !== null) {
            $matchStart = hrtime(true);
            $matched = $this->router->match($methodEnum, $path, $host !== '' ? $host : null);
            $matchUs = (int) ((hrtime(true) - $matchStart) / 1000);

            $this->metricsRegistry->histogram(
                'pulsar_route_match_us',
                'Route matching duration in microseconds',
                [10.0, 25.0, 50.0, 100.0, 250.0, 500.0, 1000.0],
            )->observe((float) $matchUs);
        } else {
            $matched = $this->router->match($methodEnum, $path, $host !== '' ? $host : null);
        }

        // Populate RouteContext for observability middleware (metrics/tracing)
        if ($this->routeContext !== null) {
            $this->routeContext->setPattern($matched->route->path);
            $this->routeContext->setName($matched->getName());
        }

        // Add route parameters to request attributes
        $request = $this->addRouteAttributesToRequest($request, $matched);

        // Apply route-specific middleware, resolving names through the registry
        if ($matched->getMiddleware() !== []) {
            $pipeline = new MiddlewarePipeline($this->container);
            foreach ($matched->getMiddleware() as $middleware) {
                if (is_string($middleware)) {
                    $resolved = $this->middlewareRegistry->resolve($middleware);
                    foreach ($resolved as $resolvedMiddleware) {
                        $pipeline->pipe($resolvedMiddleware);
                    }
                } else {
                    /** @var PsrMiddlewareInterface $middleware */
                    $pipeline->pipe($middleware);
                }
            }
            return $pipeline->dispatch(
                $request,
                fn(ServerRequestInterface $req): ResponseInterface => $this->invokeHandler($req, $matched),
            );
        }

        return $this->invokeHandler($request, $matched);
    }

    /**
     * Add matched route information to the request.
     *
     * Uses bulk withAttributes() to avoid multiple clone operations
     * when route parameters are present.
     */
    private function addRouteAttributesToRequest(
        ServerRequestInterface $request,
        MatchedRoute $matched,
    ): ServerRequestInterface {
        $attrs = [
            '_route' => $matched,
            '_route_name' => $matched->getName(),
            ...$matched->parameters,
        ];

        if ($request instanceof ServerRequest) {
            return $request->withAttributes($attrs);
        }

        // PSR-7 fallback: individual withAttribute calls
        foreach ($attrs as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        return $request;
    }

    /**
     * Invoke the route handler.
     *
     * @throws Throwable If the handler throws or is invalid
     * @throws ContainerException If a container error occurs resolving a controller
     * @throws NotFoundException If a controller binding is not found in the container
     * @throws Error If a controller class cannot be instantiated
     */
    private function invokeHandler(ServerRequestInterface $request, MatchedRoute $matched): ResponseInterface
    {
        /** @var mixed $handler */
        $handler = $matched->getHandler();

        if (is_callable($handler)) {
            /** @var mixed $response */
            $response = $handler($request, $matched->parameters);
        } elseif (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0]) && is_string($handler[1]) && class_exists($handler[0])) {
            $class = $handler[0];
            $method = $handler[1];
            $controller = $this->resolveController($class);
            $args = $this->resolveHandlerArguments($class, $method, $request, $matched->parameters);
            /** @var mixed $response */
            $response = $controller->$method(...$args);
        } elseif (is_string($handler) && class_exists($handler)) {
            $controller = $this->resolveController($handler);
            if (method_exists($controller, '__invoke')) {
                $args = $this->resolveHandlerArguments($handler, '__invoke', $request, $matched->parameters);
                /** @var mixed $response */
                $response = $controller(...$args);
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

        if (!$response instanceof ResponseInterface) {
            throw RoutingException::unexpectedReturnType(get_debug_type($response));
        }

        return $response;
    }

    /**
     * Resolve handler arguments using reflection.
     *
     * If the handler's second parameter is `array $params`, pass the raw parameters array
     * for backward compatibility. Otherwise, spread named route parameters into positional
     * arguments based on parameter names.
     *
     * @param class-string $class
     * @param array<string, string> $routeParams
     * @return list<mixed>
     */
    private function resolveHandlerArguments(
        string $class,
        string $method,
        ServerRequestInterface $request,
        array $routeParams,
    ): array {
        $cacheKey = $class . '::' . $method;

        if (!isset($this->handlerWantsRequest[$cacheKey])) {
            try {
                $reflection = new ReflectionMethod($class, $method);
                $params = $reflection->getParameters();

                $wantsRequest = false;
                if (isset($params[0])) {
                    $firstType = $params[0]->getType();
                    if ($firstType instanceof ReflectionNamedType && !$firstType->isBuiltin()) {
                        $typeName = $firstType->getName();
                        $wantsRequest = $typeName === ServerRequestInterface::class
                            || is_subclass_of($typeName, ServerRequestInterface::class);
                    }
                }

                // Legacy array-passing: if the param right after $request (or the very first
                // when there is no $request) is typed `array`, hand over the raw $routeParams.
                $arrayParamIndex = $wantsRequest ? 1 : 0;
                $usesArray = false;
                if (isset($params[$arrayParamIndex])) {
                    $type = $params[$arrayParamIndex]->getType();
                    $usesArray = $type instanceof ReflectionNamedType && $type->getName() === 'array';
                }

                $this->handlerWantsRequest[$cacheKey] = $wantsRequest;
                $this->handlerUsesArrayParams[$cacheKey] = $usesArray;
            } catch (ReflectionException) {
                // Reflection failed; fall back to legacy array-passing with $request
                $this->handlerWantsRequest[$cacheKey] = true;
                $this->handlerUsesArrayParams[$cacheKey] = true;
            }
        }

        $wantsRequest = $this->handlerWantsRequest[$cacheKey];

        if ($this->handlerUsesArrayParams[$cacheKey]) {
            return $wantsRequest ? [$request, $routeParams] : [$routeParams];
        }

        $args = $wantsRequest ? [$request] : [];

        if (!isset($this->handlerParamMap[$cacheKey])) {
            try {
                $reflection = new ReflectionMethod($class, $method);
                $paramMap = [];

                foreach ($reflection->getParameters() as $i => $param) {
                    if ($wantsRequest && $i === 0) {
                        continue; // Skip $request
                    }

                    $paramMap[] = [
                        'name' => $param->getName(),
                        'hasDefault' => $param->isDefaultValueAvailable(),
                        'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                    ];
                }

                $this->handlerParamMap[$cacheKey] = $paramMap;
            } catch (ReflectionException) {
                // Fall back to passing the array
                return $wantsRequest ? [$request, $routeParams] : [$routeParams];
            }
        }

        foreach ($this->handlerParamMap[$cacheKey] as $entry) {
            if (isset($routeParams[$entry['name']])) {
                $args = [...$args, $routeParams[$entry['name']]];
            } elseif ($entry['hasDefault']) {
                $args = [...$args, $entry['default']];
            }
            // If no route param and no default, skip: PHP will throw a clear error
        }

        return $args;
    }

    /**
     * Resolve a controller instance from the container or autowire it.
     *
     * Resolution order:
     * 1. Container binding (registered classes, deferred providers)
     * 2. Autowiring: reflect the constructor, resolve each type-hinted
     *    parameter from the container, and instantiate the controller
     *
     * Attempts container resolution first (supports registered bindings, deferred
     * providers, and autowiring). Falls back to direct instantiation only if the
     * container cannot resolve the class.
     *
     * @param class-string $class
     *
     * @throws RoutingException If the class is missing, not instantiable, or
     *         cannot be resolved (see {@see ControllerResolverInterface::resolve()})
     */
    private function resolveController(string $class): object
    {
        return $this->controllerResolver->resolve($class);
    }

    /**
     * Perform post-response cleanup and dispatch the terminate event.
     *
     * Must be called after the response has been sent to the client.
     * Dispatches KernelEvents::TERMINATE via the event dispatcher and
     * runs terminable middleware. Critical for persistent runtimes.
     */
    public function terminate(ServerRequestInterface $request, ResponseInterface $response): void
    {
        $event = new TerminateEvent($request, $response);

        // Dispatch the terminate event if the event dispatcher is available
        if ($this->container->has(EventDispatcherInterface::class)) {
            /** @var EventDispatcherInterface $dispatcher */
            $dispatcher = $this->container->get(EventDispatcherInterface::class);
            $dispatcher->dispatch($event);
        }
    }

    /**
     * Shutdown the kernel.
     *
     * Performs cleanup and releases resources.
     *
     * F3.14: every loaded extension that implements
     * `ShutdownAwareExtensionInterface` gets a `shutdown()`
     * call before the kernel marks itself unbooted. Required
     * for long-running SAPIs (RoadRunner, FrankenPHP, Swoole,
     * queue worker, supervised process) that recycle workers
     * without tearing the process down — without the hook
     * extensions cannot release database / redis / grpc
     * connections, log buffers, or in-flight worker pools.
     * Errors during an extension shutdown are caught and
     * logged but never block the shutdown of the rest:
     * leaving one extension stuck would prevent the others
     * from cleaning up at all.
     */
    public function shutdown(): void
    {
        if ($this->extensionBootstrap !== null) {
            foreach ($this->extensionBootstrap->registry->all() as $extension) {
                if (!$extension instanceof \Pulsar\Extensibility\ShutdownAwareExtensionInterface) {
                    continue;
                }
                try {
                    $extension->shutdown($this->container);
                } catch (Throwable $e) {
                    error_log(sprintf(
                        '[Pulsar] Extension shutdown failed for "%s": %s',
                        $extension->name(),
                        $e->getMessage(),
                    ));
                }
            }
        }

        $this->booted = false;
    }
}
