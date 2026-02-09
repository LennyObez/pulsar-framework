<?php

declare(strict_types=1);

namespace Pulsar\Core;

use Closure;

use function dirname;

use Error;

use function is_array;
use function is_callable;
use function is_string;

use JsonException;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\AuthManager;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Authorization\Gate;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Auth\Guard\SessionGuard;
use Pulsar\Auth\Guard\TokenGuard;
use Pulsar\Auth\Guard\TokenResolverInterface;
use Pulsar\Auth\Middleware\AuthenticationMiddleware;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Auth\Middleware\TwoFactorMiddleware;
use Pulsar\Auth\Password\PasswordHasher;
use Pulsar\Auth\Password\PasswordHasherInterface;
use Pulsar\Auth\SecurityContext;
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\RecoveryCodeVerifier;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Auth\TwoFactor\TwoFactorManager;
use Pulsar\Auth\TwoFactor\TwoFactorManagerInterface;
use Pulsar\Cache\CachedRoute;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Cache\FrameworkCacheInterface;
use Pulsar\Cache\RouteHandlerType;
use Pulsar\Config\AppConfig;
use Pulsar\Config\AuditConfig;
use Pulsar\Config\AuthConfig;
use Pulsar\Config\AuthorizationConfig;
use Pulsar\Config\CircuitBreakerConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\CsrfConfig;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Config\DeployConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\FeatureFlagConfig;
use Pulsar\Config\HealthCheckConfig;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\QueueDriverType;
use Pulsar\Config\ResilienceConfig;
use Pulsar\Config\RetryConfig;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Config\SchedulerConfig;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Config\TenancyConfig;
use Pulsar\Config\TenantDatabaseConfig;
use Pulsar\Config\TwoFactorConfig;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use Pulsar\Context\Middleware\RequestContextMiddleware;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Database\ConnectionManager;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Deploy\Check\CacheSettingsCheck;
use Pulsar\Deploy\Check\DebugModeCheck;
use Pulsar\Deploy\Check\FilesystemScanCheck;
use Pulsar\Deploy\Check\HealthEndpointCheck;
use Pulsar\Deploy\Check\Http3ReadinessCheck;
use Pulsar\Deploy\Check\HttpsReadinessCheck;
use Pulsar\Deploy\Check\IntegrityCheck;
use Pulsar\Deploy\Check\JitCheck;
use Pulsar\Deploy\Check\OpcacheCheck;
use Pulsar\Deploy\Check\RateLimitCheck;
use Pulsar\Deploy\Check\RequestSizeCheck;
use Pulsar\Deploy\Check\SecurityHeadersReadinessCheck;
use Pulsar\Deploy\Check\SeverityOverrideCheck;
use Pulsar\Deploy\Check\SkippedCheck;
use Pulsar\Deploy\Check\TrustedProxyCheck;
use Pulsar\Deploy\CheckSeverity;
use Pulsar\Deploy\DeployCheck;
use Pulsar\Deploy\DeployCheckInterface;
use Pulsar\Deploy\DeployCheckRunnerInterface;
use Pulsar\Deploy\DeploySeverity;
use Pulsar\Deploy\Runtime\PhpRuntime;
use Pulsar\Deploy\Runtime\PhpRuntimeInterface;
use Pulsar\ErrorHandling\DevelopmentRenderer;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\FeatureFlag\Exception\FeatureFlagException;
use Pulsar\FeatureFlag\FeatureFlagManager;
use Pulsar\FeatureFlag\FeatureFlagManagerInterface;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagEvaluationLog;
use Pulsar\FeatureFlag\FlagEvaluationLogInterface;
use Pulsar\FeatureFlag\FlagStorageDriver;
use Pulsar\FeatureFlag\FlagStorageInterface;
use Pulsar\FeatureFlag\Storage\FileFlagStorage;
use Pulsar\FeatureFlag\Storage\InMemoryFlagStorage;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Middleware\MetricsMiddleware;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewarePipelineInterface;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Middleware\TracingMiddleware;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseEmitter;
use Pulsar\Http\RouteContext;
use Pulsar\Integrity\IntegrityPolicy;
use Pulsar\Integrity\ManifestBuilder;
use Pulsar\Integrity\ManifestBuilderInterface;
use Pulsar\Integrity\ManifestSigner;
use Pulsar\Integrity\ManifestSignerInterface;
use Pulsar\Integrity\ManifestVerifier;
use Pulsar\Integrity\ManifestVerifierInterface;
use Pulsar\Observability\Diagnostics\DiagnosticsRenderer;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\ErrorTracking\ErrorAggregatorInterface;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Observability\Log\Logger;
use Pulsar\Observability\Log\Sink\DeferredSink;
use Pulsar\Observability\Log\Sink\DeferredSinkInterface;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Metrics\OpenMetricsExporter;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Observability\Tracing\W3CTraceContextParser;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\Driver\InMemoryDriver;
use Pulsar\Queue\Driver\SyncDriver;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\QueueManager;
use Pulsar\Queue\Retry\QueueRetryPolicy;
use Pulsar\Queue\Worker;
use Pulsar\Queue\WorkerOptions;
use Pulsar\Resilience\CircuitBreakerRegistry;
use Pulsar\Resilience\HealthCheck\HealthCheckRunner;
use Pulsar\Resilience\HealthCheck\HealthCheckRunnerInterface;
use Pulsar\Resilience\Repair\RepairRunner;
use Pulsar\Resilience\Repair\RepairRunnerInterface;
use Pulsar\Resilience\RetryPolicy;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;
use Pulsar\Routing\RoutingException;
use Pulsar\Runtime\LeakDetector;
use Pulsar\Runtime\PersistentRuntimeFactory;
use Pulsar\Runtime\PersistentRuntimeFactoryInterface;
use Pulsar\Runtime\RequestResetRegistry;
use Pulsar\Runtime\RequestSandbox;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\Scheduler;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditSinkInterface;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Csrf\CsrfMiddleware;
use Pulsar\Security\Csrf\CsrfTokenManager;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Middleware\SecurityHeadersMiddleware;
use Pulsar\Security\Session\Session;
use Pulsar\Security\Session\SessionInterface;
use Pulsar\Supervisor\PreflightCheck\PreflightRunner;
use Pulsar\Supervisor\PreflightCheck\PreflightRunnerInterface;
use Pulsar\Supervisor\Supervisor;
use Pulsar\Supervisor\SupervisorInterface;
use Pulsar\Tenancy\Middleware\TenantResolutionMiddleware;
use Pulsar\Tenancy\Resolver\HeaderTenantResolver;
use Pulsar\Tenancy\Resolver\PathPrefixTenantResolver;
use Pulsar\Tenancy\Resolver\SubdomainTenantResolver;
use Pulsar\Tenancy\TenantAwareConnectionManager;
use Pulsar\Tenancy\TenantContext;
use Pulsar\Tenancy\TenantResolverInterface;
use Pulsar\Tenancy\TenantResolverStrategy;
use Random\Engine\Secure;
use Random\Randomizer;
use ReflectionException;
use SodiumException;
use Throwable;

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

        // Config phase: load config, create services
        $configStart = hrtime(true);

        if ($this->configManager !== null) {
            if (!$cacheLoaded) {
                $this->configManager->load();
            }
            $this->registerConfigServices();
            $this->createLogger();
            $this->createTracer();
            $this->createMetrics();
            $this->createRequestContext();
            $this->createErrorTracker();
            $this->createExceptionHandler();
            $this->createSecurityServices();
            $this->createAuthServices();
            $this->createDatabaseServices();
            $this->createTenancyServices();
            $this->createFeatureFlagServices();
            $this->createSchedulerServices();
            $this->createResilienceServices();
            $this->createQueueServices();
            $this->createSupervisorServices();
            $this->createIntegrityServices();
            $this->createDeployServices();
            $this->createRuntimeServices();
            $this->registerDiagnosticsRoute();
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
        $request = $request->withAttribute('_route', $matched);
        $request = $request->withAttribute('_route_name', $matched->getName());

        foreach ($matched->parameters as $name => $value) {
            $request = $request->withAttribute($name, $value);
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
     * Register config DTOs and related services in the container.
     */
    private function registerConfigServices(): void
    {
        /** @var ConfigManager $configManager Already checked non-null before calling */
        $configManager = $this->configManager;
        $repository = $configManager->repository();
        $environment = $configManager->environment();

        $this->container->instance(ConfigManager::class, $configManager);
        $this->container->instance(ConfigManagerInterface::class, $configManager);
        $this->container->instance(ConfigRepository::class, $repository);
        $this->container->instance(Environment::class, $environment);

        /** @var AppConfig $appConfig */
        $appConfig = $repository->get(AppConfig::class);
        $this->container->instance(AppConfig::class, $appConfig);

        /** @var ObservabilityConfig $observabilityConfig */
        $observabilityConfig = $repository->get(ObservabilityConfig::class);
        $this->container->instance(ObservabilityConfig::class, $observabilityConfig);
        $this->container->instance(AuditConfig::class, $observabilityConfig->audit);

        /** @var SecurityConfig $securityConfig */
        $securityConfig = $repository->get(SecurityConfig::class);
        $this->container->instance(SecurityConfig::class, $securityConfig);
        $this->container->instance(SessionConfig::class, $securityConfig->session);
        $this->container->instance(CsrfConfig::class, $securityConfig->csrf);
        $this->container->instance(SecurityHeadersConfig::class, $securityConfig->headers);

        if ($securityConfig->auth !== null) {
            $this->container->instance(AuthConfig::class, $securityConfig->auth);
            $this->container->instance(TwoFactorConfig::class, $securityConfig->auth->twoFactor);
            $this->container->instance(AuthorizationConfig::class, $securityConfig->auth->authorization);
        }
    }

    /**
     * Create the logger from config and register in the container.
     *
     * DeferredSink is always-present as a generic extension point.
     * It's a pass-through (no buffer) — zero overhead when no sinks attached.
     * Extensions (e.g., Studio) call $deferredSink->addSink() to wire in
     * late-bound log collection during their postBoot phase.
     */
    private function createLogger(): void
    {
        /** @var ConfigManager $configManager */
        $configManager = $this->configManager;

        /** @var ObservabilityConfig $observabilityConfig */
        $observabilityConfig = $configManager->repository()->get(ObservabilityConfig::class);

        $deferredSink = new DeferredSink();
        $this->container->instance(DeferredSink::class, $deferredSink);
        $this->container->instance(DeferredSinkInterface::class, $deferredSink);
        $logger = Logger::fromConfigWithExtraSinks($observabilityConfig, [$deferredSink]);

        $this->container->instance(LoggerInterface::class, $logger);
        $this->container->instance(Logger::class, $logger);
    }

    /**
     * Create the tracing subsystem and register TracingMiddleware as outermost global middleware.
     *
     * @throws NotFoundException|ContainerException
     * @throws ReflectionException If class reflection fails during autowiring
     */
    private function createTracer(): void
    {
        /** @var ConfigManager $configManager */
        $configManager = $this->configManager;

        /** @var ObservabilityConfig $observabilityConfig */
        $observabilityConfig = $configManager->repository()->get(ObservabilityConfig::class);

        if (!$observabilityConfig->tracing->enabled) {
            return;
        }

        $collector = new InMemorySpanCollector();
        $this->container->instance(InMemorySpanCollector::class, $collector);

        /** @var Randomizer $randomizer */
        $randomizer = $this->container->get(Randomizer::class);

        // Create shared RouteContext (populated after route matching)
        if ($this->routeContext === null) {
            $this->routeContext = new RouteContext();
            $this->container->instance(RouteContext::class, $this->routeContext);
        }

        $traceContextParser = new W3CTraceContextParser();

        $tracingMiddleware = new TracingMiddleware(
            $collector,
            $traceContextParser,
            $observabilityConfig->tracing->samplingRate,
            $randomizer,
            $this->routeContext,
        );

        // Tracing is outermost: registered first
        $this->middleware->pipe($tracingMiddleware);
    }

    /**
     * Create the metrics subsystem and register MetricsMiddleware as inner global middleware.
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    private function createMetrics(): void
    {
        /** @var ConfigManager $configManager */
        $configManager = $this->configManager;

        /** @var ObservabilityConfig $observabilityConfig */
        $observabilityConfig = $configManager->repository()->get(ObservabilityConfig::class);

        if (!$observabilityConfig->metrics->enabled) {
            return;
        }

        $registry = new MetricRegistry();
        $this->container->instance(MetricRegistry::class, $registry);

        // Create shared RouteContext if not already created by tracing
        if ($this->routeContext === null) {
            $this->routeContext = new RouteContext();
            $this->container->instance(RouteContext::class, $this->routeContext);
        }

        $metricsMiddleware = new MetricsMiddleware($registry, $this->routeContext);

        // Metrics is inner: registered after tracing
        $this->middleware->pipe($metricsMiddleware);

        // Register OpenMetrics endpoint if enabled
        if ($observabilityConfig->metrics->exporterEnabled) {
            $endpoint = $observabilityConfig->metrics->exporterEndpoint;
            $this->router->get($endpoint, static function () use ($registry): Response {
                $exporter = new OpenMetricsExporter($registry);

                return new Response(
                    body: $exporter->export(),
                    headers: new HeaderBag(['content-type' => ['text/plain; version=0.0.4; charset=utf-8']]),
                );
            });
        }
    }

    /**
     * Create the request context subsystem and register RequestContextMiddleware.
     *
     * Provides correlation/causation ID propagation and request metadata
     * across HTTP, queue, and scheduler boundaries.
     *
     * @throws NotFoundException|ContainerException
     * @throws ReflectionException If class reflection fails during autowiring
     */
    private function createRequestContext(): void
    {
        $holder = new RequestContextHolder();
        $this->container->instance(RequestContextHolder::class, $holder);

        /** @var Randomizer $randomizer */
        $randomizer = $this->container->get(Randomizer::class);

        $middleware = new RequestContextMiddleware($holder, $randomizer);

        // RequestContext comes after metrics, before error tracker
        $this->middleware->pipe($middleware);
    }

    /**
     * Create the error tracking subsystem.
     */
    private function createErrorTracker(): void
    {
        /** @var ConfigManager $configManager */
        $configManager = $this->configManager;

        /** @var ObservabilityConfig $observabilityConfig */
        $observabilityConfig = $configManager->repository()->get(ObservabilityConfig::class);

        if (!$observabilityConfig->errorTracking->enabled) {
            return;
        }

        $scrubber = new SensitiveDataScrubber($observabilityConfig->errorTracking->sensitiveFields);
        $aggregator = new ErrorAggregator(
            maxGroups: $observabilityConfig->errorTracking->maxGroups,
            maxRecentEventsPerGroup: $observabilityConfig->errorTracking->maxRecentEventsPerGroup,
        );

        $this->container->instance(SensitiveDataScrubber::class, $scrubber);
        $this->container->instance(ErrorAggregator::class, $aggregator);
        $this->container->instance(ErrorAggregatorInterface::class, $aggregator);
    }

    /**
     * Create the exception handler from config and register in the container.
     *
     * @throws ContainerException If a container error occurs while resolving dependencies
     * @throws NotFoundException If a required binding is not found in the container
     * @throws ReflectionException If class reflection fails during autowiring
     */
    private function createExceptionHandler(): void
    {
        /** @var ConfigManager $configManager */
        $configManager = $this->configManager;

        /** @var AppConfig $appConfig */
        $appConfig = $configManager->repository()->get(AppConfig::class);

        $scrubber = $this->container->has(SensitiveDataScrubber::class)
            ? $this->container->get(SensitiveDataScrubber::class)
            : null;

        /** @var SensitiveDataScrubber|null $scrubber */
        $renderer = $appConfig->debug
            ? new DevelopmentRenderer($scrubber ?? new SensitiveDataScrubber())
            : new ProductionRenderer();

        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        $aggregator = $this->container->has(ErrorAggregator::class)
            ? $this->container->get(ErrorAggregator::class)
            : null;

        /** @var LoggerInterface|null $logger */
        /** @var ErrorAggregator|null $aggregator */
        /** @var SensitiveDataScrubber|null $scrubber */
        $this->exceptionHandler = new ExceptionHandler($renderer, $logger, $aggregator, $scrubber);
        $this->container->instance(ExceptionHandler::class, $this->exceptionHandler);
    }

    /**
     * Create security services and register in the container.
     *
     * Registers: Session, CsrfTokenManager, CsrfMiddleware,
     * SecurityHeadersMiddleware. If PULSAR_MASTER_KEY is set,
     * also registers MasterKey, Encryptor, and AuditLogger.
     *
     * @throws NotFoundException|ContainerException
     * @throws ReflectionException If class reflection fails during autowiring
     */
    private function createSecurityServices(): void
    {
        /** @var ConfigManager $configManager */
        $configManager = $this->configManager;
        $environment = $configManager->environment();

        /** @var SecurityConfig $securityConfig */
        $securityConfig = $configManager->repository()->get(SecurityConfig::class);

        // Session
        $session = new Session($securityConfig->session);
        $this->container->instance(Session::class, $session);
        $this->container->instance(SessionInterface::class, $session);

        // CSRF
        /** @var Randomizer $randomizer */
        $randomizer = $this->container->get(Randomizer::class);
        $csrfTokenManager = new CsrfTokenManager($session, $securityConfig->csrf, $randomizer);
        $this->container->instance(CsrfTokenManager::class, $csrfTokenManager);
        $this->container->instance(CsrfTokenManagerInterface::class, $csrfTokenManager);

        $csrfMiddleware = new CsrfMiddleware($csrfTokenManager, $securityConfig->csrf);
        $this->container->instance(CsrfMiddleware::class, $csrfMiddleware);

        // Security Headers
        $headersMiddleware = new SecurityHeadersMiddleware($securityConfig->headers);
        $this->container->instance(SecurityHeadersMiddleware::class, $headersMiddleware);

        // HmacService adapter — always available (no key required, delegates to static Hmac methods)
        $hmacService = new HmacService();
        $this->container->instance(HmacInterface::class, $hmacService);

        // Crypto + Audit (only if master key is available)
        $masterKeyHex = $environment->get('PULSAR_MASTER_KEY');

        if ($masterKeyHex !== null && $masterKeyHex !== '') {
            try {
                $masterKey = MasterKey::fromHex($masterKeyHex);
                $this->container->instance(MasterKey::class, $masterKey);
                $this->container->instance(KeyProviderInterface::class, $masterKey);

                $encryptor = Encryptor::fromMasterKey($masterKey);
                $this->container->instance(Encryptor::class, $encryptor);
                $this->container->instance(EncryptorInterface::class, $encryptor);

                // Framework cache (skip if pre-boot already registered)
                if (!$this->container->has(FrameworkCache::class)) {
                    $encrypt = $environment->get('CACHE_ENCRYPT') === 'true'
                        || $environment->get('CACHE_ENCRYPT') === '1';
                    $configPath = $this->configManager?->configPath();
                    if ($configPath !== null) {
                        $frameworkCache = new FrameworkCache(dirname($configPath), $masterKey, $hmacService, $encrypt, $encrypt ? $encryptor : null);
                        $this->container->instance(FrameworkCache::class, $frameworkCache);
                        $this->container->instance(FrameworkCacheInterface::class, $frameworkCache);
                    }
                }

                // Audit logger with HMAC chain
                /** @var ObservabilityConfig $obsConfig */
                $obsConfig = $configManager->repository()->get(ObservabilityConfig::class);

                if ($obsConfig->audit->enabled) {
                    $auditKey = $masterKey->deriveSubKey(2, 'audit___');
                    $auditSink = new AuditFileSink($obsConfig->audit->logPath);
                    $this->container->instance(AuditSinkInterface::class, $auditSink);
                    $this->container->instance(AuditFileSink::class, $auditSink);

                    $contextHolder = $this->container->has(RequestContextHolder::class)
                        ? $this->container->get(RequestContextHolder::class)
                        : null;

                    /** @var RequestContextHolder|null $contextHolder */
                    $auditLogger = new AuditLogger($auditSink, $auditKey, $randomizer, $contextHolder);
                    $this->container->instance(AuditLogger::class, $auditLogger);
                    $this->container->instance(AuditLoggerInterface::class, $auditLogger);
                }
            } catch (SecurityException | SodiumException) {
                // Master key is invalid or sodium operation failed — skip crypto/audit registration.
                // Session, CSRF, and headers still work without it.
            }
        }
    }

    /**
     * Create authentication and authorization services.
     *
     * Registers: PasswordHasher, SessionGuard, TokenGuard (if resolver bound),
     * AuthManager, RoleRegistry, Gate, SecurityContext, and auth middleware.
     * If 2FA is enabled, also registers TOTP and recovery code services.
     *
     * @throws ContainerException If a container error occurs while resolving dependencies
     * @throws NotFoundException If a required binding is not found in the container
     * @throws ReflectionException If class reflection fails during autowiring
     */
    private function createAuthServices(): void
    {
        /** @var ConfigManager $configManager */
        $configManager = $this->configManager;

        /** @var SecurityConfig $securityConfig */
        $securityConfig = $configManager->repository()->get(SecurityConfig::class);

        if ($securityConfig->auth === null) {
            return;
        }

        $authConfig = $securityConfig->auth;

        // Password hasher
        $passwordHasher = new PasswordHasher();
        $this->container->instance(PasswordHasher::class, $passwordHasher);
        $this->container->instance(PasswordHasherInterface::class, $passwordHasher);

        // Auth manager
        $authManager = new AuthManager($authConfig->defaultGuard);

        // Session guard
        if ($this->container->has(SessionInterface::class)) {
            /** @var SessionInterface $session */
            $session = $this->container->get(SessionInterface::class);
            $sessionGuard = new SessionGuard($session);
            $this->container->instance(SessionGuard::class, $sessionGuard);
            $authManager->addGuard($sessionGuard);
        }

        // Token guard (only if a TokenResolverInterface is bound)
        if ($this->container->has(TokenResolverInterface::class)) {
            /** @var TokenResolverInterface $tokenResolver */
            $tokenResolver = $this->container->get(TokenResolverInterface::class);
            $tokenGuard = new TokenGuard($tokenResolver);
            $this->container->instance(TokenGuard::class, $tokenGuard);
            $authManager->addGuard($tokenGuard);
        }

        $this->container->instance(AuthManager::class, $authManager);
        $this->container->instance(AuthManagerInterface::class, $authManager);

        // Role registry
        $roleRegistry = new InMemoryRoleRegistry();

        foreach ($authConfig->authorization->roles as $roleName => $roleData) {
            /** @var array<string, mixed> $roleData */
            $roleRegistry->register(Role::fromArray($roleName, $roleData));
        }

        $this->container->instance(InMemoryRoleRegistry::class, $roleRegistry);
        $this->container->instance(RoleRegistryInterface::class, $roleRegistry);

        // Gate
        $gate = new Gate($roleRegistry, $authConfig->authorization->superRoles);
        $this->container->instance(Gate::class, $gate);
        $this->container->instance(GateInterface::class, $gate);

        // 2FA services
        if ($authConfig->twoFactor->enabled) {
            /** @var Randomizer $randomizer */
            $randomizer = $this->container->get(Randomizer::class);

            $totpGenerator = new TotpGenerator(
                codeDigits: $authConfig->twoFactor->codeDigits,
                period: $authConfig->twoFactor->codePeriod,
                randomizer: $randomizer,
            );
            $totpVerifier = new TotpVerifier($totpGenerator, $authConfig->twoFactor->verificationWindow);
            $recoveryCodeGenerator = new RecoveryCodeGenerator($randomizer);
            $recoveryCodeVerifier = new RecoveryCodeVerifier();

            $twoFactorManager = new TwoFactorManager(
                generator: $totpGenerator,
                verifier: $totpVerifier,
                recoveryCodeGenerator: $recoveryCodeGenerator,
                recoveryCodeVerifier: $recoveryCodeVerifier,
                issuer: $authConfig->twoFactor->issuer,
                recoveryCodeCount: $authConfig->twoFactor->recoveryCodeCount,
            );

            $this->container->instance(TotpGenerator::class, $totpGenerator);
            $this->container->instance(TotpVerifier::class, $totpVerifier);
            $this->container->instance(RecoveryCodeGenerator::class, $recoveryCodeGenerator);
            $this->container->instance(RecoveryCodeVerifier::class, $recoveryCodeVerifier);
            $this->container->instance(TwoFactorManager::class, $twoFactorManager);
            $this->container->instance(TwoFactorManagerInterface::class, $twoFactorManager);
        }

        // Middleware
        $authenticationMiddleware = new AuthenticationMiddleware($authManager);
        $this->container->instance(AuthenticationMiddleware::class, $authenticationMiddleware);

        $auditLogger = $this->container->has(AuditLogger::class)
            ? $this->container->get(AuditLogger::class)
            : null;

        $authContextHolder = $this->container->has(RequestContextHolder::class)
            ? $this->container->get(RequestContextHolder::class)
            : null;

        /** @var AuditLogger|null $auditLogger */
        /** @var RequestContextHolder|null $authContextHolder */
        $authorizationMiddleware = new AuthorizationMiddleware($gate, $auditLogger, $authContextHolder);
        $this->container->instance(AuthorizationMiddleware::class, $authorizationMiddleware);

        $twoFactorMiddleware = new TwoFactorMiddleware();
        $this->container->instance(TwoFactorMiddleware::class, $twoFactorMiddleware);

        // Register middleware aliases
        $this->middlewareRegistry->alias('auth', $authorizationMiddleware);
        $this->middlewareRegistry->alias('2fa', $twoFactorMiddleware);

        // Add AuthenticationMiddleware as global middleware (lightweight — only attaches SecurityContext)
        $this->middleware->pipe($authenticationMiddleware);
    }

    /**
     * Create database services and register in the container.
     *
     * Only activates when config/database.php was loaded (optional).
     * Registers DatabaseConfig, ConnectionManager, and ConnectionManagerInterface.
     */
    private function createDatabaseServices(): void
    {
        /** @var ConfigManager $configManager Already checked non-null before calling */
        $configManager = $this->configManager;
        $repository = $configManager->repository();

        if (!$repository->has(DatabaseConfig::class)) {
            return;
        }

        /** @var DatabaseConfig $dbConfig */
        $dbConfig = $repository->get(DatabaseConfig::class);
        $this->container->instance(DatabaseConfig::class, $dbConfig);

        $connectionManager = ConnectionManager::fromConfig($dbConfig);
        $this->container->instance(ConnectionManager::class, $connectionManager);
        $this->container->instance(ConnectionManagerInterface::class, $connectionManager);
    }

    /**
     * Create multi-tenancy services and register in the container.
     *
     * Only activates when config/tenancy.php was loaded and tenancy is enabled.
     * Registers TenancyConfig, TenantContext, TenantResolver, and TenantResolutionMiddleware.
     * If database services are available, decorates ConnectionManager with tenant awareness.
     *
     * @throws ContainerException If a container error occurs while resolving dependencies
     * @throws NotFoundException If a required binding is not found in the container
     * @throws ReflectionException If class reflection fails during autowiring
     */
    private function createTenancyServices(): void
    {
        /** @var ConfigManager $configManager Already checked non-null before calling */
        $configManager = $this->configManager;
        $repository = $configManager->repository();

        if (!$repository->has(TenancyConfig::class)) {
            return;
        }

        /** @var TenancyConfig $tenancyConfig */
        $tenancyConfig = $repository->get(TenancyConfig::class);
        $this->container->instance(TenancyConfig::class, $tenancyConfig);
        $this->container->instance(TenantDatabaseConfig::class, $tenancyConfig->database);

        if (!$tenancyConfig->enabled) {
            return;
        }

        // Tenant context
        $tenantContext = new TenantContext();
        $this->container->instance(TenantContext::class, $tenantContext);

        // Resolver
        $resolver = match ($tenancyConfig->resolver) {
            TenantResolverStrategy::Header => new HeaderTenantResolver($tenancyConfig),
            TenantResolverStrategy::Subdomain => new SubdomainTenantResolver($tenancyConfig),
            TenantResolverStrategy::Path => new PathPrefixTenantResolver($tenancyConfig),
        };

        $this->container->instance(TenantResolverInterface::class, $resolver);
        $this->container->instance($resolver::class, $resolver);

        // Middleware
        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        /** @var LoggerInterface|null $logger */
        $tenantMiddleware = new TenantResolutionMiddleware($resolver, $tenantContext, $tenancyConfig, $logger);
        $this->container->instance(TenantResolutionMiddleware::class, $tenantMiddleware);

        // Tenant-aware connection manager (decorate existing if available)
        if ($this->container->has(ConnectionManagerInterface::class)) {
            /** @var ConnectionManagerInterface $innerManager */
            $innerManager = $this->container->get(ConnectionManagerInterface::class);

            $tenantAwareManager = new TenantAwareConnectionManager($innerManager, $tenantContext, $tenancyConfig);
            $this->container->instance(TenantAwareConnectionManager::class, $tenantAwareManager);
        }
    }

    /**
     * Create feature flag services and register in the container.
     *
     * Only activates when config/features.php was loaded and feature flags are enabled.
     * Registers FlagStorage, FlagEvaluationLog, and FeatureFlagManager.
     *
     * @throws FeatureFlagException If flag storage fails during initial flag loading
     * @throws JsonException If flag serialization fails during initial flag loading
     */
    private function createFeatureFlagServices(): void
    {
        /** @var ConfigManager $configManager Already checked non-null before calling */
        $configManager = $this->configManager;
        $repository = $configManager->repository();

        if (!$repository->has(FeatureFlagConfig::class)) {
            return;
        }

        /** @var FeatureFlagConfig $flagConfig */
        $flagConfig = $repository->get(FeatureFlagConfig::class);
        $this->container->instance(FeatureFlagConfig::class, $flagConfig);

        if (!$flagConfig->enabled) {
            return;
        }

        // Storage
        $storage = match ($flagConfig->storage) {
            FlagStorageDriver::Memory => new InMemoryFlagStorage(),
            FlagStorageDriver::File => new FileFlagStorage($flagConfig->filePath),
        };

        // Load pre-configured flags
        foreach ($flagConfig->flags as $name => $data) {
            /** @var array<string, mixed> $data */
            $storage->set(FlagDefinition::fromArray($name, $data));
        }

        $this->container->instance(FlagStorageInterface::class, $storage);
        $this->container->instance($storage::class, $storage);

        // Evaluation log
        $evaluationLog = new FlagEvaluationLog();
        $this->container->instance(FlagEvaluationLog::class, $evaluationLog);
        $this->container->instance(FlagEvaluationLogInterface::class, $evaluationLog);

        // Manager
        $manager = new FeatureFlagManager($storage, $evaluationLog, $flagConfig->defaultState);
        $this->container->instance(FeatureFlagManager::class, $manager);
        $this->container->instance(FeatureFlagManagerInterface::class, $manager);
    }

    /**
     * Create scheduler services and register in the container.
     *
     * Only activates when config/scheduler.php was loaded and scheduler is enabled.
     * Registers JobRegistry and Scheduler.
     *
     * @throws ContainerException If a container error occurs while resolving dependencies
     * @throws NotFoundException If a required binding is not found in the container
     * @throws ReflectionException If class reflection fails during autowiring
     */
    private function createSchedulerServices(): void
    {
        /** @var ConfigManager $configManager Already checked non-null before calling */
        $configManager = $this->configManager;
        $repository = $configManager->repository();

        if (!$repository->has(SchedulerConfig::class)) {
            return;
        }

        /** @var SchedulerConfig $schedulerConfig */
        $schedulerConfig = $repository->get(SchedulerConfig::class);
        $this->container->instance(SchedulerConfig::class, $schedulerConfig);

        if (!$schedulerConfig->enabled) {
            return;
        }

        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        $metrics = $this->container->has(MetricRegistry::class)
            ? $this->container->get(MetricRegistry::class)
            : null;

        $registry = new JobRegistry();
        $this->container->instance(JobRegistry::class, $registry);

        $contextHolder = $this->container->has(RequestContextHolder::class)
            ? $this->container->get(RequestContextHolder::class)
            : null;

        /** @var Randomizer|null $schedulerRandomizer */
        $schedulerRandomizer = $this->container->has(Randomizer::class)
            ? $this->container->get(Randomizer::class)
            : null;

        /** @var LoggerInterface|null $logger */
        /** @var MetricRegistry|null $metrics */
        /** @var RequestContextHolder|null $contextHolder */
        $scheduler = new Scheduler($registry, $logger, $metrics, $contextHolder, $schedulerRandomizer);
        $this->container->instance(Scheduler::class, $scheduler);
    }

    /**
     * Create resilience services and register in the container.
     *
     * Only activates when config/resilience.php was loaded and resilience is enabled.
     * Registers RetryPolicy, CircuitBreakerRegistry, HealthCheckRunner, and RepairRunner.
     */
    private function createResilienceServices(): void
    {
        /** @var ConfigManager $configManager Already checked non-null before calling */
        $configManager = $this->configManager;
        $repository = $configManager->repository();

        if (!$repository->has(ResilienceConfig::class)) {
            return;
        }

        /** @var ResilienceConfig $resilienceConfig */
        $resilienceConfig = $repository->get(ResilienceConfig::class);
        $this->container->instance(ResilienceConfig::class, $resilienceConfig);
        $this->container->instance(RetryConfig::class, $resilienceConfig->retry);
        $this->container->instance(CircuitBreakerConfig::class, $resilienceConfig->circuitBreaker);
        $this->container->instance(HealthCheckConfig::class, $resilienceConfig->healthCheck);

        if (!$resilienceConfig->enabled) {
            return;
        }

        // Retry policy (default)
        $retryPolicy = RetryPolicy::fromConfig($resilienceConfig->retry);
        $this->container->instance(RetryPolicy::class, $retryPolicy);

        // Circuit breaker registry
        $cbRegistry = new CircuitBreakerRegistry($resilienceConfig->circuitBreaker);
        $this->container->instance(CircuitBreakerRegistry::class, $cbRegistry);

        // Health check runner
        $healthCheckRunner = new HealthCheckRunner();
        $this->container->instance(HealthCheckRunner::class, $healthCheckRunner);
        $this->container->instance(HealthCheckRunnerInterface::class, $healthCheckRunner);

        // Repair runner
        $repairRunner = new RepairRunner();
        $this->container->instance(RepairRunner::class, $repairRunner);
        $this->container->instance(RepairRunnerInterface::class, $repairRunner);
    }

    /**
     * Create queue services and register in the container.
     *
     * Only activates when config/queue.php was loaded and queue is enabled.
     * Registers QueueConfig, QueueDriver, QueueManager, WorkerOptions,
     * Worker, QueueRetryPolicy, and DeadLetterQueue.
     *
     * @throws ContainerException If a container error occurs while resolving dependencies
     * @throws NotFoundException If a required binding is not found in the container
     * @throws ReflectionException If class reflection fails during autowiring
     */
    private function createQueueServices(): void
    {
        /** @var ConfigManager $configManager Already checked non-null before calling */
        $configManager = $this->configManager;
        $repository = $configManager->repository();

        if (!$repository->has(QueueConfig::class)) {
            return;
        }

        /** @var QueueConfig $queueConfig */
        $queueConfig = $repository->get(QueueConfig::class);
        $this->container->instance(QueueConfig::class, $queueConfig);

        if (!$queueConfig->enabled) {
            return;
        }

        // Queue driver
        /** @var Randomizer $randomizer */
        $randomizer = $this->container->get(Randomizer::class);

        $driver = match ($queueConfig->driver) {
            QueueDriverType::Sync => new SyncDriver($randomizer),
            QueueDriverType::Memory => new InMemoryDriver($randomizer),
            QueueDriverType::Database => $this->container->has(QueueDriverInterface::class)
                ? $this->container->get(QueueDriverInterface::class)
                : new InMemoryDriver(),
        };

        if (!$this->container->has(QueueDriverInterface::class)) {
            $this->container->instance(QueueDriverInterface::class, $driver);
        }

        // Queue manager (with context propagation)
        $contextHolder = $this->container->has(RequestContextHolder::class)
            ? $this->container->get(RequestContextHolder::class)
            : null;

        /** @var RequestContextHolder|null $contextHolder */
        $queueManager = new QueueManager($queueConfig, $driver, $contextHolder);
        $this->container->instance(QueueManager::class, $queueManager);

        // Worker options
        $workerOptions = WorkerOptions::fromConfig($queueConfig);
        $this->container->instance(WorkerOptions::class, $workerOptions);

        // Worker
        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        /** @var LoggerInterface|null $logger */
        $worker = new Worker($driver, $workerOptions, $logger, $contextHolder);
        $this->container->instance(Worker::class, $worker);

        // Retry policy
        $retryPolicy = QueueRetryPolicy::fromConfig($queueConfig);
        $this->container->instance(QueueRetryPolicy::class, $retryPolicy);

        // Dead letter queue
        $deadLetterQueue = new DeadLetterQueue($driver);
        $this->container->instance(DeadLetterQueue::class, $deadLetterQueue);
    }

    /**
     * Create supervisor services and register in the container.
     *
     * Only activates when config/supervisor.php was loaded and supervisor is enabled.
     * Registers SupervisorConfig and Supervisor.
     *
     * @throws ContainerException If a container error occurs while resolving dependencies
     * @throws NotFoundException If a required binding is not found in the container
     * @throws ReflectionException If class reflection fails during autowiring
     */
    private function createSupervisorServices(): void
    {
        /** @var ConfigManager $configManager Already checked non-null before calling */
        $configManager = $this->configManager;
        $repository = $configManager->repository();

        if (!$repository->has(SupervisorConfig::class)) {
            return;
        }

        /** @var SupervisorConfig $supervisorConfig */
        $supervisorConfig = $repository->get(SupervisorConfig::class);
        $this->container->instance(SupervisorConfig::class, $supervisorConfig);

        if (!$supervisorConfig->enabled) {
            return;
        }

        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        $auditLogger = $this->container->has(AuditLogger::class)
            ? $this->container->get(AuditLogger::class)
            : null;

        /** @var LoggerInterface|null $logger */
        /** @var AuditLogger|null $auditLogger */
        $supervisor = new Supervisor(
            config: $supervisorConfig,
            logger: $logger,
            auditLogger: $auditLogger,
        );
        $this->container->instance(Supervisor::class, $supervisor);
        $this->container->instance(SupervisorInterface::class, $supervisor);

        // Preflight runner (extracted for direct injection)
        $preflightRunner = new PreflightRunner([]);
        $this->container->instance(PreflightRunner::class, $preflightRunner);
        $this->container->instance(PreflightRunnerInterface::class, $preflightRunner);
    }

    /**
     * Create file integrity services and register in the container.
     *
     * Only activates when config/integrity.php was loaded and integrity is enabled.
     * Registers IntegrityConfig, IntegrityPolicy, ManifestBuilder, ManifestVerifier,
     * and ManifestSigner (if MasterKey is available).
     *
     * @throws ContainerException If a container error occurs while resolving dependencies
     * @throws NotFoundException If a required binding is not found in the container
     * @throws ReflectionException If class reflection fails during autowiring
     */
    private function createIntegrityServices(): void
    {
        /** @var ConfigManager $configManager Already checked non-null before calling */
        $configManager = $this->configManager;
        $repository = $configManager->repository();

        if (!$repository->has(IntegrityConfig::class)) {
            return;
        }

        /** @var IntegrityConfig $integrityConfig */
        $integrityConfig = $repository->get(IntegrityConfig::class);
        $this->container->instance(IntegrityConfig::class, $integrityConfig);

        if (!$integrityConfig->enabled) {
            return;
        }

        // Policy
        $policy = IntegrityPolicy::fromConfig($integrityConfig);
        $this->container->instance(IntegrityPolicy::class, $policy);

        // Builder and verifier need a base path
        $basePath = $configManager->configPath() !== null
            ? dirname($configManager->configPath())
            : '.';

        $builder = new ManifestBuilder($basePath);
        $this->container->instance(ManifestBuilder::class, $builder);
        $this->container->instance(ManifestBuilderInterface::class, $builder);

        $verifier = new ManifestVerifier($basePath);
        $this->container->instance(ManifestVerifier::class, $verifier);
        $this->container->instance(ManifestVerifierInterface::class, $verifier);

        // Signer (requires MasterKey)
        if ($this->container->has(MasterKey::class)) {
            /** @var MasterKey $masterKey */
            $masterKey = $this->container->get(MasterKey::class);

            try {
                /** @var HmacInterface $hmacService */
                $hmacService = $this->container->get(HmacInterface::class);
                $signer = new ManifestSigner($hmacService, $masterKey);
                $this->container->instance(ManifestSigner::class, $signer);
                $this->container->instance(ManifestSignerInterface::class, $signer);
            } catch (SodiumException) {
                // Signing key derivation failed — skip signer registration
            }
        }
    }

    /**
     * Create deploy check services and register in the container.
     *
     * Only activates when config/deploy.php was loaded.
     * Registers DeployConfig, PhpRuntime, and DeployCheck with all checks wired.
     */
    private function createDeployServices(): void
    {
        /** @var ConfigManager $configManager Already checked non-null before calling */
        $configManager = $this->configManager;
        $repository = $configManager->repository();

        if (!$repository->has(DeployConfig::class)) {
            return;
        }

        /** @var DeployConfig $deployConfig */
        $deployConfig = $repository->get(DeployConfig::class);
        $this->container->instance(DeployConfig::class, $deployConfig);

        $phpRuntime = new PhpRuntime();
        $this->container->instance(PhpRuntimeInterface::class, $phpRuntime);

        /** @var AppConfig $appConfig */
        $appConfig = $repository->get(AppConfig::class);

        /** @var SecurityConfig $securityConfig */
        $securityConfig = $repository->get(SecurityConfig::class);

        $deployCheck = new DeployCheck();

        $this->registerCheckOrSkip($deployCheck, 'debug-mode', $deployConfig, static fn(): DeployCheckInterface => new DebugModeCheck($appConfig));

        $this->registerCheckOrSkip($deployCheck, 'opcache', $deployConfig, static fn(): DeployCheckInterface => new OpcacheCheck($phpRuntime));

        $this->registerCheckOrSkip($deployCheck, 'jit', $deployConfig, static fn(): DeployCheckInterface => new JitCheck($phpRuntime));

        $this->registerCheckOrSkip($deployCheck, 'cache-settings', $deployConfig, function (): ?DeployCheckInterface {
            if (!$this->container->has(FrameworkCache::class)) {
                return null;
            }
            /** @var FrameworkCache $cache */
            $cache = $this->container->get(FrameworkCache::class);

            return new CacheSettingsCheck($cache);
        });

        $this->registerCheckOrSkip($deployCheck, 'filesystem-scan', $deployConfig, function (): ?DeployCheckInterface {
            if (!$this->container->has(FrameworkCache::class)) {
                return null;
            }
            /** @var FrameworkCache $cache */
            $cache = $this->container->get(FrameworkCache::class);

            return new FilesystemScanCheck($cache);
        });

        $this->registerCheckOrSkip($deployCheck, 'security-headers', $deployConfig, static fn(): DeployCheckInterface => new SecurityHeadersReadinessCheck($securityConfig->headers));

        $this->registerCheckOrSkip($deployCheck, 'https-readiness', $deployConfig, static fn(): DeployCheckInterface => new HttpsReadinessCheck($securityConfig));

        $this->registerCheckOrSkip($deployCheck, 'http3-readiness', $deployConfig, static fn(): DeployCheckInterface => new Http3ReadinessCheck($deployConfig));

        $this->registerCheckOrSkip($deployCheck, 'health-endpoint', $deployConfig, fn(): DeployCheckInterface => new HealthEndpointCheck($this->router));

        $this->registerCheckOrSkip($deployCheck, 'rate-limiting', $deployConfig, static fn(): DeployCheckInterface => new RateLimitCheck($securityConfig));

        $this->registerCheckOrSkip($deployCheck, 'request-size-limits', $deployConfig, static fn(): DeployCheckInterface => new RequestSizeCheck($deployConfig));

        $this->registerCheckOrSkip($deployCheck, 'trusted-proxies', $deployConfig, static fn(): DeployCheckInterface => new TrustedProxyCheck($deployConfig));

        $this->registerCheckOrSkip($deployCheck, 'integrity', $deployConfig, function (): ?DeployCheckInterface {
            if (!$this->container->has(IntegrityConfig::class)) {
                return null;
            }
            /** @var IntegrityConfig $integrityConfig */
            $integrityConfig = $this->container->get(IntegrityConfig::class);

            return new IntegrityCheck($integrityConfig);
        });

        $this->container->instance(DeployCheck::class, $deployCheck);
        $this->container->instance(DeployCheckRunnerInterface::class, $deployCheck);
    }

    /**
     * Register a deploy check or a skipped stub based on config and dependency availability.
     *
     * @param Closure(): ?DeployCheckInterface $factory
     */
    private function registerCheckOrSkip(
        DeployCheck $deployCheck,
        string $name,
        DeployConfig $deployConfig,
        Closure $factory,
    ): void {
        $config = $deployConfig->checkConfig($name);

        if (!$config['enabled']) {
            return;
        }

        $check = $factory();

        if ($check === null) {
            $deployCheck->register(new SkippedCheck(
                $name,
                'Required dependency not configured',
                $config['severity'] === 'fail' ? CheckSeverity::Error : CheckSeverity::Warning,
            ));
            return;
        }

        $severity = DeploySeverity::from($config['severity']);

        // Wrap with severity override if configured
        $deployCheck->register(new SeverityOverrideCheck($check, $severity));
    }

    /**
     * Create runtime services for the persistent worker runtime.
     *
     * Registers RuntimeConfig, RequestResetRegistry, LeakDetector, and
     * RequestSandbox. Also populates the registry with known resettable
     * and evictable service IDs.
     *
     * @throws ContainerException If a container resolution fails
     * @throws NotFoundException If a required service is not registered
     * @throws ReflectionException If class reflection fails during autowiring
     */
    private function createRuntimeServices(): void
    {
        /** @var ConfigManager $configManager Already checked non-null before calling */
        $configManager = $this->configManager;
        $repository = $configManager->repository();

        if (!$repository->has(RuntimeConfig::class)) {
            return;
        }

        /** @var RuntimeConfig $runtimeConfig */
        $runtimeConfig = $repository->get(RuntimeConfig::class);
        $this->container->instance(RuntimeConfig::class, $runtimeConfig);

        // Create the request reset registry
        $registry = new RequestResetRegistry();

        // Register evictable services (re-created per request by middleware)
        $registry->registerEvictable(SecurityContext::class);

        // Register resettable services (state reset between requests)
        if ($this->container->has(RequestContextHolder::class)) {
            $registry->registerResettable(RequestContextHolder::class);
        }

        if ($this->container->has(TenantContext::class)) {
            $registry->registerResettable(TenantContext::class);
        }

        if ($this->container->has(FlagEvaluationLog::class)) {
            $registry->registerResettable(FlagEvaluationLog::class);
        }

        if ($this->container->has(AuthManagerInterface::class)) {
            $registry->registerResettable(AuthManagerInterface::class);
        }

        $this->container->instance(RequestResetRegistry::class, $registry);

        // Create leak detector
        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        /** @var LoggerInterface|null $logger */
        $leakDetector = new LeakDetector(logger: $logger);
        $this->container->instance(LeakDetector::class, $leakDetector);

        // Create request sandbox
        $sandbox = new RequestSandbox($this->container, $registry, $leakDetector);
        $this->container->instance(RequestSandbox::class, $sandbox);

        // Runtime factory (encapsulates PersistentRuntime construction)
        $runtimeFactory = new PersistentRuntimeFactory($this->container);
        $this->container->instance(PersistentRuntimeFactory::class, $runtimeFactory);
        $this->container->instance(PersistentRuntimeFactoryInterface::class, $runtimeFactory);
    }

    /**
     * Register the diagnostics route (debug mode only).
     *
     * @throws RoutingException If the router is locked in strict cache mode
     */
    private function registerDiagnosticsRoute(): void
    {
        /** @var ConfigManager $configManager */
        $configManager = $this->configManager;

        /** @var AppConfig $appConfig */
        $appConfig = $configManager->repository()->get(AppConfig::class);

        if (!$appConfig->debug) {
            return;
        }

        if (!$this->container->has(MetricRegistry::class)) {
            return;
        }

        $container = $this->container;

        $this->router->get('/_pulsar/diagnostics', static function () use ($container): Response {
            /** @var MetricRegistry $registry */
            $registry = $container->get(MetricRegistry::class);

            $collector = $container->has(InMemorySpanCollector::class)
                ? $container->get(InMemorySpanCollector::class)
                : null;

            $aggregator = $container->has(ErrorAggregator::class)
                ? $container->get(ErrorAggregator::class)
                : null;

            /** @var InMemorySpanCollector|null $collector */
            /** @var ErrorAggregator|null $aggregator */
            $renderer = new DiagnosticsRenderer($registry, $collector, $aggregator);

            return Response::html($renderer->render());
        });
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
