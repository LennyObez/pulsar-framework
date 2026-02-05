<?php

declare(strict_types=1);

namespace Pulsar\Core;

use Error;

use function is_array;
use function is_callable;
use function is_string;

use JsonException;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
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
use Pulsar\Auth\TwoFactor\RecoveryCodeGenerator;
use Pulsar\Auth\TwoFactor\RecoveryCodeVerifier;
use Pulsar\Auth\TwoFactor\TotpGenerator;
use Pulsar\Auth\TwoFactor\TotpVerifier;
use Pulsar\Auth\TwoFactor\TwoFactorManager;
use Pulsar\Auth\TwoFactor\TwoFactorManagerInterface;
use Pulsar\Config\AppConfig;
use Pulsar\Config\AuditConfig;
use Pulsar\Config\AuthConfig;
use Pulsar\Config\AuthorizationConfig;
use Pulsar\Config\CircuitBreakerConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigRepository;
use Pulsar\Config\CsrfConfig;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\FeatureFlagConfig;
use Pulsar\Config\HealthCheckConfig;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Config\ResilienceConfig;
use Pulsar\Config\RetryConfig;
use Pulsar\Config\SchedulerConfig;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\SecurityHeadersConfig;
use Pulsar\Config\SessionConfig;
use Pulsar\Config\TenancyConfig;
use Pulsar\Config\TenantDatabaseConfig;
use Pulsar\Config\TwoFactorConfig;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use Pulsar\Database\ConnectionManager;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\ErrorHandling\DevelopmentRenderer;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\FeatureFlag\Exception\FeatureFlagException;
use Pulsar\FeatureFlag\FeatureFlagManager;
use Pulsar\FeatureFlag\FeatureFlagManagerInterface;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagEvaluationLog;
use Pulsar\FeatureFlag\FlagStorageDriver;
use Pulsar\FeatureFlag\FlagStorageInterface;
use Pulsar\FeatureFlag\Storage\FileFlagStorage;
use Pulsar\FeatureFlag\Storage\InMemoryFlagStorage;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Middleware\MetricsMiddleware;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Middleware\TracingMiddleware;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseEmitter;
use Pulsar\Observability\Diagnostics\DiagnosticsRenderer;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Observability\Log\Logger;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Metrics\OpenMetricsExporter;
use Pulsar\Observability\Tracing\InMemorySpanCollector;
use Pulsar\Resilience\CircuitBreakerRegistry;
use Pulsar\Resilience\HealthCheck\HealthCheckRunner;
use Pulsar\Resilience\Repair\RepairRunner;
use Pulsar\Resilience\RetryPolicy;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\Scheduler;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditSinkInterface;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Csrf\CsrfMiddleware;
use Pulsar\Security\Csrf\CsrfTokenManager;
use Pulsar\Security\Csrf\CsrfTokenManagerInterface;
use Pulsar\Security\Exception\SecurityException;
use Pulsar\Security\Middleware\SecurityHeadersMiddleware;
use Pulsar\Security\Session\Session;
use Pulsar\Security\Session\SessionInterface;
use Pulsar\Tenancy\Middleware\TenantResolutionMiddleware;
use Pulsar\Tenancy\Resolver\HeaderTenantResolver;
use Pulsar\Tenancy\Resolver\PathPrefixTenantResolver;
use Pulsar\Tenancy\Resolver\SubdomainTenantResolver;
use Pulsar\Tenancy\TenantAwareConnectionManager;
use Pulsar\Tenancy\TenantContext;
use Pulsar\Tenancy\TenantResolverInterface;
use Pulsar\Tenancy\TenantResolverStrategy;
use RuntimeException;
use SodiumException;

use function sprintf;

use Throwable;

/**
 * Pulsar Kernel
 *
 * The kernel is responsible for bootstrapping the application,
 * managing the lifecycle, and orchestrating the request/response cycle.
 *
 * Boot pipeline order:
 * Config -> Logger -> Tracer -> Metrics -> ErrorTracker -> ExceptionHandler
 * -> Security -> Auth -> Database -> Tenancy -> FeatureFlags -> Scheduler -> Resilience
 * -> DiagnosticsRoute -> Extensions
 */
#[Internal]
final class Kernel
{
    public private(set) bool $booted = false;
    private ContainerInterface $container;
    private Router $router;
    private MiddlewarePipeline $middleware;
    private MiddlewareRegistry $middlewareRegistry;
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
        $this->middlewareRegistry = new MiddlewareRegistry();
        $this->extensionBootstrap = $extensionBootstrap;
        $this->configManager = $configManager;

        // Register core services in container
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(MiddlewareRegistry::class, $this->middlewareRegistry);
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
     * 2. Logger creation
     * 3. Tracer creation (TracingMiddleware as outermost global middleware)
     * 4. Metrics creation (MetricsMiddleware as inner global middleware)
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
     * 16. Extension boot phase
     *
     * @throws ContainerException If a container error occurs during bootstrap
     * @throws NotFoundException If a required binding is not found during bootstrap
     * @throws FeatureFlagException If flag storage fails during boot
     * @throws JsonException If flag serialization fails during boot
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Config phase: load config, create services
        if ($this->configManager !== null) {
            $this->configManager->load();
            $this->registerConfigServices();
            $this->createLogger();
            $this->createTracer();
            $this->createMetrics();
            $this->createErrorTracker();
            $this->createExceptionHandler();
            $this->createSecurityServices();
            $this->createAuthServices();
            $this->createDatabaseServices();
            $this->createTenancyServices();
            $this->createFeatureFlagServices();
            $this->createSchedulerServices();
            $this->createResilienceServices();
            $this->registerDiagnosticsRoute();
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
     * @throws RoutingException When no route matches or method is not allowed
     * @throws RuntimeException If the handler is invalid or returns an unexpected type
     * @throws ContainerException If a container error occurs resolving a controller
     * @throws NotFoundException If a controller binding is not found in the container
     * @throws Error If a controller class cannot be instantiated
     */
    private function dispatchRoute(Request $request): Response
    {
        $host = $request->header('Host');
        $matched = $this->router->match($request->method, $request->path, $host);

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
     * @throws RuntimeException If the handler is invalid or returns an unexpected type
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
     *
     * @throws ContainerException If a container error occurs during resolution
     * @throws NotFoundException If the resolved binding is not found
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
     * Create the tracing subsystem and register TracingMiddleware as outermost global middleware.
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

        $tracingMiddleware = new TracingMiddleware(
            $collector,
            $observabilityConfig->tracing->samplingRate,
        );

        // Tracing is outermost: registered first
        $this->middleware->pipe($tracingMiddleware);
    }

    /**
     * Create the metrics subsystem and register MetricsMiddleware as inner global middleware.
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

        $metricsMiddleware = new MetricsMiddleware($registry);

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
    }

    /**
     * Create the exception handler from config and register in the container.
     *
     * @throws ContainerException If a container error occurs while resolving dependencies
     * @throws NotFoundException If a required binding is not found in the container
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

        $aggregator = $this->container->has(ErrorAggregator::class)
            ? $this->container->get(ErrorAggregator::class)
            : null;

        $scrubber = $this->container->has(SensitiveDataScrubber::class)
            ? $this->container->get(SensitiveDataScrubber::class)
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
        $csrfTokenManager = new CsrfTokenManager($session, $securityConfig->csrf);
        $this->container->instance(CsrfTokenManager::class, $csrfTokenManager);
        $this->container->instance(CsrfTokenManagerInterface::class, $csrfTokenManager);

        $csrfMiddleware = new CsrfMiddleware($csrfTokenManager, $securityConfig->csrf);
        $this->container->instance(CsrfMiddleware::class, $csrfMiddleware);

        // Security Headers
        $headersMiddleware = new SecurityHeadersMiddleware($securityConfig->headers);
        $this->container->instance(SecurityHeadersMiddleware::class, $headersMiddleware);

        // Crypto + Audit (only if master key is available)
        $masterKeyHex = $environment->get('PULSAR_MASTER_KEY');

        if ($masterKeyHex !== null && $masterKeyHex !== '') {
            try {
                $masterKey = MasterKey::fromHex($masterKeyHex);
                $this->container->instance(MasterKey::class, $masterKey);

                $encryptor = new Encryptor($masterKey);
                $this->container->instance(Encryptor::class, $encryptor);

                // Audit logger with HMAC chain
                /** @var ObservabilityConfig $obsConfig */
                $obsConfig = $configManager->repository()->get(ObservabilityConfig::class);

                if ($obsConfig->audit->enabled) {
                    $auditKey = $masterKey->deriveSubKey(2, 'audit___');
                    $auditSink = new AuditFileSink($obsConfig->audit->logPath);
                    $this->container->instance(AuditSinkInterface::class, $auditSink);
                    $this->container->instance(AuditFileSink::class, $auditSink);

                    $auditLogger = new AuditLogger($auditSink, $auditKey);
                    $this->container->instance(AuditLogger::class, $auditLogger);
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
            $totpGenerator = new TotpGenerator(
                codeDigits: $authConfig->twoFactor->codeDigits,
                period: $authConfig->twoFactor->codePeriod,
            );
            $totpVerifier = new TotpVerifier($totpGenerator, $authConfig->twoFactor->verificationWindow);
            $recoveryCodeGenerator = new RecoveryCodeGenerator();
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

        /** @var AuditLogger|null $auditLogger */
        $authorizationMiddleware = new AuthorizationMiddleware($gate, $auditLogger);
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

        /** @var LoggerInterface|null $logger */
        /** @var MetricRegistry|null $metrics */
        $scheduler = new Scheduler($registry, $logger, $metrics);
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

        // Repair runner
        $repairRunner = new RepairRunner();
        $this->container->instance(RepairRunner::class, $repairRunner);
    }

    /**
     * Register the diagnostics route (debug mode only).
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
     * Shutdown the kernel.
     *
     * Performs cleanup and releases resources.
     */
    public function shutdown(): void
    {
        $this->booted = false;
    }
}
