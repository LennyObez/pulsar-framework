<?php

declare(strict_types=1);

namespace Pulsar\Core;

use Error;
use JsonException;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Cache\CacheManifest;
use Pulsar\Cache\FrameworkCache;
use Pulsar\Cache\FrameworkCacheInterface;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Config\ConfigRepository;
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
use Pulsar\Core\Boot\ConfigDiagnosticsReporter;
use Pulsar\Core\Boot\DeferredComposition;
use Pulsar\Core\Boot\ExtensionConfigPublisher;
use Pulsar\Core\Boot\ExtensionDiscovery;
use Pulsar\Core\Boot\ExtensionSandbox;
use Pulsar\Core\Boot\ExtensionViewPathRegistrar;
use Pulsar\Core\Boot\ProjectRouteLoader;
use Pulsar\Core\Controller\ArgumentResolverChain;
use Pulsar\Core\Controller\ArgumentResolverLifecycle;
use Pulsar\Core\Controller\ArgumentResolverRegistryInterface;
use Pulsar\Core\Controller\ControllerResolverInterface;
use Pulsar\Core\Controller\HandlerArgumentResolverInterface;
use Pulsar\Core\Controller\HandlerDescriptor;
use Pulsar\Core\Controller\HandlerParameter;
use Pulsar\Core\Controller\HandlerSignature;
use Pulsar\Core\Controller\ReflectionControllerResolver;
use Pulsar\Core\Event\TerminateEvent;
use Pulsar\Core\Wiring\AssetWiring;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Core\Wiring\WiringList;
use Pulsar\ErrorHandling\ExceptionHandler;
use Pulsar\ErrorHandling\ProductionRenderer;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\FeatureFlag\Exception\FeatureFlagException;
use Pulsar\Http\Message\BodyTooLargeException;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\CallableRequestHandler;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewarePipelineInterface;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Middleware\PostRoutingPipeline;
use Pulsar\Http\ResponseEmitter;
use Pulsar\Http\ResponseStatus;
use Pulsar\Http\RouteContext;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\Binding\ExplicitBinding;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteAccessReporter;
use Pulsar\Routing\RouteCollisionReporter;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;
use Pulsar\Routing\RoutingException;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\Security\Crypto\MasterKey;
use Random\Engine\Secure;
use Random\Randomizer;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use SodiumException;
use Throwable;

use function array_key_exists;
use function dirname;
use function error_log;
use function getenv;
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

    /**
     * Router state captured at boot() entry, restored on shutdown() so a re-boot
     * does not accumulate duplicate routes/bindings. Null until first boot().
     *
     * @var array{routes: list<Route>, namedRoutes: array<string, Route>, staticRoutes: array<string, array<string, Route>>, dynamicRouteBuckets: array<string, array<string, array<int, Route>>>, registeredRouteKeys: array<string, array<string, array<string, Route>>>, collisions: list<\Pulsar\Routing\RouteCollision>, explicitBindings: list<ExplicitBinding>, locked: bool, hasHostConstrainedRoutes: bool}|null
     */
    private ?array $routerSnapshot = null;

    /**
     * Middleware-pipeline stack captured at boot() entry, restored on shutdown().
     *
     * @var list<PsrMiddlewareInterface|class-string<PsrMiddlewareInterface>>|null
     */
    private ?array $middlewareSnapshot = null;

    /**
     * Argument-resolver chain captured at boot() entry, restored on shutdown().
     *
     * Captured and put back through {@see $argumentResolverLifecycle}, which is
     * the only object that can write the chain's resolver list.
     *
     * @var list<HandlerArgumentResolverInterface>|null
     */
    private ?array $argumentResolverSnapshot = null;

    /**
     * Post-routing middleware stack captured at boot() entry, restored on shutdown().
     *
     * @var list<PsrMiddlewareInterface|class-string<PsrMiddlewareInterface>>|null
     */
    private ?array $postRoutingSnapshot = null;

    /**
     * Everything reflected about a handler, memoised for the process lifetime
     * and keyed by `Class::method`. Handlers are immutable once routed, so the
     * cache never needs invalidating: a persistent worker reflects each handler
     * exactly once per process, not once per request.
     *
     * @var array<string, HandlerDescriptor>
     */
    private array $handlerDescriptors = [];

    private readonly ControllerResolverInterface $controllerResolver;
    private readonly ArgumentResolverChain $argumentResolvers;

    /**
     * The capability to restore the argument-resolver chain, taken once.
     *
     * Held here and nowhere else. The chain is published in the container so a
     * wiring can append to it; this handle is not, so nothing that reaches the
     * container can replace the resolvers the boot registered — including the
     * bound-model resolver, whose claims are sealed precisely because
     * displacing them would hand a handler an object no authorization hook
     * approved.
     */
    private readonly ArgumentResolverLifecycle $argumentResolverLifecycle;
    private readonly PostRoutingPipeline $postRouting;
    private readonly DeferredComposition $deferredComposition;

    public function __construct(
        ?ContainerInterface $container = null,
        ?Router $router = null,
        ?ExtensionBootstrap $extensionBootstrap = null,
        ?ConfigManager $configManager = null,
        ?ControllerResolverInterface $controllerResolver = null,
        ?ArgumentResolverChain $argumentResolvers = null,
    ) {
        $this->container = $container ?? new Container();
        $this->controllerResolver = $controllerResolver ?? new ReflectionControllerResolver($this->container);
        $this->router = $router ?? new Router();
        $this->middleware = new MiddlewarePipeline($this->container);
        $this->middlewareRegistry = new MiddlewareRegistry();
        $this->argumentResolvers = $argumentResolvers ?? new ArgumentResolverChain();
        // Taken here, before any wiring, extension or route file can run, which
        // is what makes the refusal every later caller gets meaningful.
        $this->argumentResolverLifecycle = $this->argumentResolvers->issueLifecycle();
        $this->postRouting = new PostRoutingPipeline($this->container);
        $this->deferredComposition = new DeferredComposition();
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

        // Bound here rather than in a wiring so a bare `new Kernel()` — the shape
        // every test and every micro-entry-point uses — has the same seams as a
        // fully wired application, and so a wiring can contribute to them without
        // depending on the concrete chain or pipeline.
        $this->container->instance(ArgumentResolverChain::class, $this->argumentResolvers);
        $this->container->instance(ArgumentResolverRegistryInterface::class, $this->argumentResolvers);
        $this->container->instance(PostRoutingPipeline::class, $this->postRouting);
        $this->container->instance(DeferredComposition::class, $this->deferredComposition);

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

        // Anchor path helpers to the real project root before ANY config file or
        // wiring can call var_path()/base_path(). base_path() otherwise falls
        // back to getcwd(), which under PHP-FPM is the public/ document root — so
        // a relative default like 'var/cache' would resolve INTO the webroot.
        $this->anchorBasePath();

        // Capture the pre-boot router/middleware baseline so shutdown() can
        // restore it. A re-boot (handle() after shutdown()) re-runs the wirings,
        // route loading, and extension boot, which would otherwise stack
        // duplicate middleware and routes onto the already-populated instances.
        $this->routerSnapshot = $this->router->snapshot();
        $this->middlewareSnapshot = $this->middleware->snapshot();
        $this->argumentResolverSnapshot = $this->argumentResolverLifecycle->snapshot();
        $this->postRoutingSnapshot = $this->postRouting->snapshot();

        $bootStart = hrtime(true);

        // Cache-aware boot: attempt to load config from FrameworkCache
        $cacheLoaded = false;
        $routesCached = false;

        // When a strict route cache is loaded, the cached routes are
        // authoritative and the router is locked below.
        //
        // The lock used to be absolute, and one wiring — AssetWiring — was
        // skipped to get around it. That closed one of eight: every other
        // route-registering wiring still ran, `I18nWiring` reached the locked
        // router third in the boot order, and `optimize --strict` produced a
        // deployment that could not boot. Skipping wirings one at a time was
        // never going to reach the end of that list, and each skip also loses
        // the container bindings that wiring makes (AssetWiring's
        // AssetController among them) for a problem that is entirely about
        // routes.
        //
        // {@see Router::add()} now decides it where the information is: a
        // registration identical to a route already in the cached table is a
        // replay of what wrote the cache and is dropped; anything else is drift
        // between the deployed code and its cache and still refuses to boot. So
        // every wiring runs on a strict-cached boot exactly as it does on a cold
        // one, and none of them has to know a cache exists.
        $strictRouteCache = false;

        $cacheStart = hrtime(true);

        // Bind FrameworkCache BEFORE the gate below. It is otherwise bound by
        // SecurityWiring, which runs ~60 lines later with the rest of the
        // wirings — so on a cold boot the gate's has() check was always false,
        // the cache was never loaded, and config/routes/container were rebuilt
        // on every request no matter what `optimize` wrote. This is the single
        // pre-boot construction for every entry point (the dev server no longer
        // does its own).
        $this->preBindFrameworkCache();

        // The INTERFACE first, the concrete class only as a fallback. Every read
        // here used to key on FrameworkCache::class alone, which is `final` — so
        // a third party could not supply that binding at all, and an application
        // that bound its own FrameworkCacheInterface implementation (the only
        // thing the stable `#[Api(since: '1.0.0')]` contract can mean) had it
        // silently ignored, with preBindFrameworkCache() then overwriting the
        // interface binding with the framework's own. The contract was published
        // and never consulted. The concrete id stays in the ladder because the
        // framework and its tests bind it, and dropping it would turn "cache
        // present" into "cache silently off" for anyone who bound only that.
        $cacheBinding = match (true) {
            $this->container->has(FrameworkCacheInterface::class) => FrameworkCacheInterface::class,
            $this->container->has(FrameworkCache::class) => FrameworkCache::class,
            default => null,
        };

        if ($this->configManager !== null && $cacheBinding !== null) {
            /** @var FrameworkCacheInterface $frameworkCache */
            $frameworkCache = $this->container->get($cacheBinding);
            $configPath = $this->configManager->configPath();

            if ($configPath !== null) {
                // Read as `mixed` and validated key by key below. `load()`
                // declares a shape; an implementation the framework did not
                // write RETURNS one, and the two are not the same thing. Reading
                // the declared shape off a foreign payload was an undefined-key
                // warning followed by a property read on a non-object — a 500 on
                // every request of an application that was working, produced by
                // a cache whose entire job is to be an optimization. Every key
                // that fails its check is treated as absent, which lands the boot
                // on the cold path it would have taken with no cache at all.
                /** @var mixed $cached */
                $cached = $frameworkCache->load($configPath);

                /** @var mixed $cachedConfig */
                $cachedConfig = is_array($cached) ? ($cached['config'] ?? null) : null;

                if ($cachedConfig instanceof ConfigRepository) {
                    $cacheLoaded = $this->configManager->loadFromCache($cachedConfig);
                }

                $hints = self::readableResolutionHints(
                    is_array($cached) ? ($cached['containerHints'] ?? null) : null,
                );

                if ($hints !== null) {
                    $this->container->setResolutionHints($hints);
                }

                /** @var mixed $manifest */
                $manifest = is_array($cached) ? ($cached['manifest'] ?? null) : null;

                if ($manifest instanceof CacheManifest && $manifest->strict) {
                    $strictRouteCache = true;
                }

                // Apply the cached route table to the router — both halves of it:
                // the routes, and the Router::model() declarations that qualify
                // them. Loading routes alone is what made BindingScope::Root and
                // a named parent relation development-only features: the route
                // files that declare them are skipped below precisely BECAUSE
                // the routes are cached, so the declarations had nowhere else to
                // come from and a deliberately unscoped nested child reverted to
                // a scoped lookup that finds nothing.
                //
                // The declarations are rebuilt first and the whole table is
                // abandoned if any of them contradicts itself. Serving the routes
                // without them would be this defect again, quieter: a route table
                // present and correct, and the statements qualifying it gone.
                // Leaving $routesCached false instead sends the boot down the
                // cold path, where the route files run and declare for real.
                //
                // Both keys are read with `??` and then VALIDATED, not merely
                // read. `bindings` was added to the `load()` shape after the
                // interface was stamped `since: 1.0.0`, so an implementation
                // written against the published contract omits it — that was the
                // case the `??` was added for, and it is the smaller half. The
                // larger half is that an implementation the kernel did not
                // construct can return either key in any shape at all, and a
                // wrongly-shaped `bindings` or `routes` detonated exactly like a
                // missing one used to. A payload that cannot state its route
                // table, or cannot state the declarations that qualify it, is
                // treated as one whose declarations contradict themselves: the
                // route table is left alone and the boot goes down the cold
                // path.
                if (is_array($cached)) {
                    $routes = CachedRouteReconstructor::reconstruct($cached['routes'] ?? null);
                    $bindings = CachedRouteReconstructor::reconstructBindings($cached['bindings'] ?? null);

                    if ($routes !== null && $routes !== [] && $bindings !== null) {
                        $this->router->loadRoutes($routes);
                        $this->router->loadBindings($bindings);
                        $routesCached = true;

                        if ($strictRouteCache) {
                            $this->router->lock();
                        }
                    }
                }
            }
        }

        $cacheLoadUs = (int) ((hrtime(true) - $cacheStart) / 1000);

        // Build artifact verification (production mode). The verifier bootstraps
        // the crypto it needs to authenticate a signed manifest from the master
        // key itself, so it does not depend on SecurityWiring (wired below) and a
        // signed manifest is verified fail-closed wherever this runs.
        BuildArtifactVerifier::verify($this->container, $this->configManager);

        // Register shared Randomizer (CSPRNG) singleton
        $randomizer = new Randomizer(new Secure());
        $this->container->instance(Randomizer::class, $randomizer);

        // Config phase: load config, create services via wiring classes
        $configStart = hrtime(true);

        if ($this->configManager !== null) {
            // Canonical, order-significant boot wiring list (single source of
            // truth shared with the wiring-contract harness). Built BEFORE config
            // load so a wiring that owns a config section can register its loader:
            // its DTO is then constructed into the ConfigRepository during load()
            // — the single source of truth — instead of being read ad-hoc from the
            // file inside wire(). ConfigManager never imports the DTO
            // (ProvidesConfigLoaders inverts the dependency).
            $wirings = WiringList::default();
            $wirings[] = new AssetWiring();

            if (!$cacheLoaded) {
                ConfigLoaderRegistrar::register($this->configManager, $wirings);
                $this->configManager->load();
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

        // Engage the extension capability sandbox before any extension registers
        // or boots. Without a policy the scoping proxies are bypassed and every
        // extension runs with full host privileges. Deny-by-default:
        // extensions not listed in config/extensions.php are capped at Community
        // regardless of the tier their manifest requests. A no-op when a policy
        // was already configured explicitly.
        if ($this->extensionBootstrap !== null) {
            ExtensionSandbox::harden($this->extensionBootstrap, $this->configManager?->configPath());

            // Publish the configuration each extension ships. Without this the
            // registry is absent, and eleven providers fall back to hard-coded
            // defaults while the config file they ship goes unread.
            ExtensionConfigPublisher::publish(
                $this->extensionBootstrap,
                $this->configManager?->configPath(),
                $this->container,
            );
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

        // Composition decisions that could not be taken during wire(): every
        // extension has registered and booted and every project route file has
        // loaded, so a gate on an optional binding now reads the final container
        // instead of one no extension has touched yet. Absence stays silent —
        // an unregistered binding simply leaves its callback unrun.
        $this->deferredComposition->apply($this->container);

        // All routes (framework wirings, project, extensions) are now registered.
        // Surface any collision where a later route shadowed an earlier one for the
        // same method+path: warn in production, fail closed in debug so a silent
        // wrong-page bug (e.g. an extension shadowing a project route) cannot ship.
        $debug = false;
        if ($this->container->has(AppConfig::class)) {
            /** @var AppConfig $appConfig */
            $appConfig = $this->container->get(AppConfig::class);
            $debug = $appConfig->debug;
        }
        /** @var LoggerInterface|null $collisionLogger */
        $collisionLogger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;
        RouteCollisionReporter::report($this->router, $collisionLogger, $debug);

        // Same point in boot, the other routing fact that is invisible until
        // something reads it back: a framework route that never declared who may
        // reach it. Its exposure would otherwise be decided by whether this
        // deployment's pipeline happens to include AuthorizationMiddleware, which
        // default-denies an empty permission list — open in one install, closed in
        // the next, chosen by nobody in either. Warns in production, fails closed
        // in debug.
        RouteAccessReporter::report($this->router, $collisionLogger, $debug);

        // Surface config diagnostics (extension load warnings that went to a
        // NullLogger, and config files for extensions disabled via
        // extensions.enabled) now that the real logger is wired.
        ConfigDiagnosticsReporter::report($this->configManager, $this->extensionBootstrap, $collisionLogger);

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
     * Export PULSAR_BASE_PATH from the config directory's parent (the project
     * root) when it is not already set, so every path helper resolves against
     * the real root regardless of the process CWD.
     *
     * `dirname($configPath)` is the same CWD-independent root the framework
     * already trusts for the framework cache, the secrets vault, the database
     * path and extension discovery. Set-once and only-when-unset: an explicit
     * PULSAR_BASE_PATH from an FPM pool, a systemd unit, or the scaffolded front
     * controller always wins, and this only repairs the entry points that never
     * exported it (a non-scaffolded FPM deployment, the CLI, the dev server).
     */
    private function anchorBasePath(): void
    {
        $existing = getenv('PULSAR_BASE_PATH');

        if ($existing !== false && $existing !== '') {
            return;
        }

        $configPath = $this->configManager?->configPath();

        if ($configPath !== null) {
            putenv('PULSAR_BASE_PATH=' . dirname($configPath));
        }
    }

    /**
     * Construct and bind {@see FrameworkCache} early enough for the cache-load
     * gate in boot() to see it — before the wirings that consume config run.
     *
     * Uses only what is available this early: the config path, PULSAR_MASTER_KEY
     * (and its optional previous key) and the CACHE_ENCRYPT flag, all resolved
     * through the Environment so a value set only in .env is honoured here too —
     * consistently with the runtime SecurityWiring, not a bare getenv() that
     * would silently miss .env-only values. Binds both the class and the interface so downstream
     * consumers (e.g. the optimize command's FrameworkCacheInterface injection)
     * still resolve. Degrades to no cache — never a fatal — when the key is
     * absent or invalid: caching is an optimization, not a boot requirement.
     *
     * Respects an existing binding, so a caller that pre-registered its own
     * cache (or a test) is not overridden. The base path matches SecurityWiring
     * (`dirname($configPath)` = the project root), so both resolve to the same
     * `var/cache/framework` directory, and SecurityWiring's later bind is a
     * harmless no-op once this has run.
     *
     * "An existing binding" means EITHER id. The guard used to test only
     * {@see FrameworkCache}, which is final and therefore unbindable by anyone
     * but the framework — so an application that bound its own
     * {@see FrameworkCacheInterface} implementation passed the guard, and the
     * last line of this method then replaced that binding with the framework's
     * own. The only way to supply an implementation of a stable, published
     * interface was silently undone.
     */
    private function preBindFrameworkCache(): void
    {
        if (
            $this->configManager === null
            || $this->container->has(FrameworkCache::class)
            || $this->container->has(FrameworkCacheInterface::class)
        ) {
            return;
        }

        $masterKeyHex = $this->earlyEnv('PULSAR_MASTER_KEY');

        if ($masterKeyHex === null) {
            return;
        }

        $configPath = $this->configManager->configPath();

        if ($configPath === null) {
            return;
        }

        try {
            $previousKeyHex = $this->earlyEnv('PULSAR_MASTER_KEY_PREVIOUS');
            $masterKey = MasterKey::fromHex($masterKeyHex, $previousKeyHex);

            $encryptFlag = $this->earlyEnv('CACHE_ENCRYPT');
            $encrypt = $encryptFlag === 'true' || $encryptFlag === '1';
            // A default-suite encryptor suffices: the encryption key is derived
            // from the master key (suite-independent) and decrypt auto-detects
            // the stored suite, so this loads a cache written under any suite.
            $encryptor = $encrypt ? Encryptor::fromMasterKey($masterKey) : null;

            $frameworkCache = new FrameworkCache(
                dirname($configPath),
                $masterKey,
                new HmacService(),
                $encrypt,
                $encryptor,
            );

            $this->container->instance(FrameworkCache::class, $frameworkCache);
            $this->container->instance(FrameworkCacheInterface::class, $frameworkCache);
        } catch (Throwable) {
            // Invalid key or a sodium failure: skip the cache, boot normally.
        }
    }

    /**
     * The container resolution hints from a cache payload, or null.
     *
     * Hints are documented as fallible — a hint that does not fit the class it
     * names is dropped at resolution time and the container falls back to
     * reflection — so this validates the SHAPE and lets the container judge the
     * contents. What it refuses is the shape: a payload whose `containerHints`
     * is not a map of class names to lists of `{name, type}` pairs would
     * otherwise be handed to the container as though it were one.
     *
     * @return array<class-string, list<array{name: string, type: class-string}>>|null
     */
    private static function readableResolutionHints(mixed $hints): ?array
    {
        if (!is_array($hints) || $hints === []) {
            return null;
        }

        $readable = [];

        /** @var mixed $parameters */
        foreach ($hints as $class => $parameters) {
            if (!is_string($class) || $class === '' || !is_array($parameters)) {
                return null;
            }

            $list = [];

            /** @var mixed $parameter */
            foreach ($parameters as $parameter) {
                if (!is_array($parameter)) {
                    return null;
                }

                /** @var mixed $name */
                $name = $parameter['name'] ?? null;
                /** @var mixed $type */
                $type = $parameter['type'] ?? null;

                if (!is_string($name) || $name === '' || !is_string($type) || $type === '') {
                    return null;
                }

                /** @var class-string $type */
                $list[] = ['name' => $name, 'type' => $type];
            }

            /** @var class-string $class */
            $readable[$class] = $list;
        }

        return $readable;
    }

    /**
     * Resolve an env value this early in boot, preferring the {@see Environment}
     * (which honours .env) once config has loaded, and falling back to the
     * process environment when it has not — the cache pre-bind can run before
     * config load. Returns null for absent or empty values.
     */
    private function earlyEnv(string $key): ?string
    {
        try {
            $value = $this->configManager?->environment()->get($key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        } catch (Throwable) {
            // Environment not loaded this early; fall back to the process env.
        }

        $raw = getenv($key);

        return ($raw === false || $raw === '') ? null : $raw;
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
     * Refuses to mutate the middleware pipeline after the
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
            // A programming error, not a runtime
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
     * Returns a response for every input. Nothing escapes to the caller — and
     * therefore to the SAPI, which would print the class, the message, the
     * absolute source path and the stack trace with `display_errors` on.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // boot() used to sit outside this guard, so a poisoned config, a
            // failing wiring or an extension that threw during registration
            // escaped handle() entirely and reached the SAPI as an uncaught
            // fatal. Inside the guard it becomes a rendered error response like
            // any other failure.
            $this->boot();

            // Set the dispatch handler once (cached by the pipeline for subsequent requests)
            if ($this->middleware->count() > 0 && !$this->dispatchHandlerSet) {
                $this->middleware->setHandler(new CallableRequestHandler(
                    fn(ServerRequestInterface $req): ResponseInterface => $this->dispatchWithErrorHandling($req),
                ));
                $this->dispatchHandlerSet = true;
            }

            // Reset route context for this request (worker reuse safety)
            $this->routeContext?->reset();

            if ($this->dispatchHandlerSet) {
                return $this->middleware->handle($request);
            }

            // No global middleware: dispatch directly (still error-guarded).
            return $this->dispatchWithErrorHandling($request);
        } catch (Throwable $e) {
            // Last-resort safety net: reached when boot() fails, or when a
            // global middleware throws. The route-dispatch path is already
            // guarded inside dispatchWithErrorHandling(), so its errors are
            // converted to a Response at the innermost handler and flow back
            // out through the pipeline, picking up security headers like any 200.
            return $this->handleException($e, $request);
        }
    }

    /**
     * Dispatch the route, converting any thrown error into a Response at the
     * innermost pipeline handler so the error response flows back out through
     * every global middleware — SecurityHeadersMiddleware in particular — and
     * receives the same header set (CSP, HSTS, framing, COOP/COEP/CORP, etc.)
     * as a 200. Without this, 404/405/500 responses were produced outside the
     * pipeline and shipped with none of the application security headers.
     */
    private function dispatchWithErrorHandling(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->dispatchRoute($request);
        } catch (Throwable $e) {
            return $this->handleException($e, $request);
        }
    }

    /**
     * Convert a Throwable into an error Response via the registered exception
     * handler, falling back to the minimum-leak ProductionRenderer when none is
     * wired. Never re-throw to the SAPI: that would leak file paths and a
     * stack trace.
     */
    private function handleException(Throwable $e, ServerRequestInterface $request): ResponseInterface
    {
        if ($this->exceptionHandler !== null) {
            try {
                return $this->exceptionHandler->handle($e, $request);
            } catch (Throwable $handlerFailure) {
                // A handler wired against a half-booted container can throw
                // while rendering. Letting that escape would replace a generic
                // page with a SAPI stack trace — exactly what the handler
                // exists to prevent.
                error_log(sprintf(
                    '[Pulsar] Exception handler failed while rendering %s: %s',
                    $e::class,
                    $handlerFailure->getMessage(),
                ));
            }
        }

        return $this->renderFallbackError($e, $request);
    }

    /**
     * Minimum-leak fallback when no `ExceptionHandler` is
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
            $e instanceof RoutingException && $e->isNotImplemented() => ResponseStatus::NotImplemented,
            default => ResponseStatus::InternalServerError,
        };

        // This branch runs when no logger reached the client's error either —
        // the exception handler is absent or itself failed. Without this line
        // a boot failure would be invisible everywhere: generic page to the
        // client, nothing in any log.
        if ($status->isServerError()) {
            error_log(sprintf('[Pulsar] Unhandled %s: %s', $e::class, $e->getMessage()));
        }

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
     * Every phase is guarded. Nothing throws past this method: it is the outermost
     * frame the framework controls, and whatever escapes it is rendered by the SAPI
     * with `display_errors` deciding whether the client sees a stack trace.
     */
    public function run(): void
    {
        try {
            $request = ServerRequest::fromGlobals();
        } catch (Throwable $e) {
            // fromGlobals() builds the request that the pipeline needs, so a
            // failure here has no request to hand to handle() and no pipeline
            // to travel back out through. A body over the 10 MiB cap used to
            // land in the SAPI as an uncaught BodyTooLargeException: HTTP 200
            // with the class, the message, the absolute path and the trace.
            $this->emitPreRequestFailure($e);

            return;
        }

        $response = $this->handle($request);

        try {
            new ResponseEmitter()->emit($response, $request->getMethod());
        } catch (Throwable $e) {
            error_log('[Pulsar] Response emission failed: ' . $e->getMessage());

            return;
        }

        try {
            $this->terminate($request, $response);
        } catch (Throwable $e) {
            // The response is already on the wire. A throw here would append a
            // trace to the body the client is part-way through reading.
            error_log('[Pulsar] terminate() failed: ' . $e->getMessage());
        }
    }

    /**
     * Emit a response for a failure that happened before a request object existed.
     *
     * No pipeline can run without a request, so the page carries its own
     * security headers ({@see ProductionRenderer::response()}). The request
     * method is unknown — parsing is what failed — so the emitter is not told
     * one, and writes a body.
     */
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

        // An unrecognized verb (PROPFIND, garbage) must not surface as a 500.
        // tryFrom yields null instead of throwing, mapped to 501 Not
        // Implemented via the routing exception handler — RFC 9110 §15.6.2.
        $methodEnum = Method::tryFrom($method) ?? throw RoutingException::notImplemented($method);

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

        // Innermost dispatch. When nothing registered post-routing middleware this
        // is the same closure the route pipeline already received and the same
        // direct call the no-middleware branch already made — no pipeline is
        // constructed, no frame is added.
        //
        // $matched travels as an ARGUMENT to both pipelines, not as the `_route`
        // attribute. Every frame between here and the handler can rewrite that
        // attribute, and nothing below reads it back: invokeHandler() and
        // resolveHandlerArguments() both use the $matched closed over here. A
        // middleware deriving authority from a rewritten attribute would
        // therefore be deciding for a route this kernel is not serving — see
        // DispatchedRouteAwareInterface, which the pipelines bind for the
        // middleware that must not be told a different route than the one whose
        // handler is about to run.
        $dispatch = $this->postRouting->isEmpty()
            ? fn(ServerRequestInterface $req): ResponseInterface => $this->invokeHandler(
                self::restoreDispatchedRoute($req, $matched),
                $matched,
            )
            : fn(ServerRequestInterface $req): ResponseInterface => $this->postRouting->dispatch(
                self::restoreDispatchedRoute($req, $matched),
                fn(ServerRequestInterface $inner): ResponseInterface => $this->invokeHandler(
                    self::restoreDispatchedRoute($inner, $matched),
                    $matched,
                ),
                $matched,
            );

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
            return $pipeline->dispatch($request, $dispatch, $matched);
        }

        return $dispatch($request);
    }

    /**
     * Put the dispatched route back on the request before the handler frame.
     *
     * `_route` is written once, in {@see addRouteAttributesToRequest()}, and
     * then travels through route-level middleware like any other attribute —
     * rewritable by every frame it passes. This kernel never reads it back, so a
     * rewrite cannot change which handler runs; all it can do is tell whatever
     * reads the attribute inside the dispatch that a different route is being
     * served. That claim is false by construction, and the frames it reaches are
     * the ones the request is about to be handled by.
     *
     * So the value the kernel wrote is restored at the two boundaries the kernel
     * owns: entering the post-routing stack, which undoes anything route-level
     * middleware did to it, and entering the handler frame, which undoes
     * anything the post-routing stack did. What the handler and the argument
     * resolvers read is therefore the route whose handler is running. The
     * identity comparison is what keeps this free: the attribute is already the
     * dispatched route on every request nothing tampered with, and no clone is
     * made.
     *
     * This is a floor, not the mechanism. Between those boundaries the attribute
     * is still ordinary request surface — one route-level middleware can rewrite
     * it for the next, and one post-routing middleware for the next — so a
     * middleware that derives AUTHORITY from the route takes it through
     * {@see DispatchedRouteAwareInterface} instead, which is handed down as an
     * argument and sits on no frame at all.
     */
    private static function restoreDispatchedRoute(
        ServerRequestInterface $request,
        MatchedRoute $matched,
    ): ServerRequestInterface {
        return $request->getAttribute('_route') === $matched
            ? $request
            : $request->withAttribute('_route', $matched);
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
     * Build the argument list for a routed controller method.
     *
     * If the handler's second parameter is `array $params`, pass the raw parameters array
     * for backward compatibility — that handler asked for the unconverted route
     * parameters and never consults the resolver chain.
     *
     * Otherwise the WHOLE chain is consulted first and its claims merged, and
     * only then is each declared parameter filled from the first source that
     * has it: a claim on its name, then the route parameter of the same name,
     * then the declared default. Merging before filling is what lets one
     * resolver supply a parameter another resolver knows nothing about; the
     * fillability of a parameter is decided once, here, against every claim
     * that exists, and never by a resolver looking at the route alone.
     *
     * ## Which parameters get a value, and which slot each value lands in
     *
     * These are two questions, and conflating them is what broke every
     * application once already.
     *
     * WHICH is unchanged and stays unchanged: a parameter nothing can fill is
     * omitted, and the kernel keeps going. A handler declaring
     * `show(string $missing, string $present = 'x')` on `/show/{present}` has
     * been called, with the route's value bound to `$missing`, for as long as
     * the framework has existed. That declaration is a mistake, but it is a
     * mistake thousands of routes are written around, and a framework that
     * converts it into a 500 in a patch release breaks every one of them. An
     * earlier revision of this method stopped building the list at the first
     * unfillable parameter; measured differentially against the previous
     * kernel, eleven ordinary handler shapes went from 200 to 500 — every shape
     * with a required parameter the route cannot fill followed by anything that
     * can. It is not a rare shape and it is not ill-formed PHP.
     *
     * WHERE is what changes, and only where it has to. Omitting a parameter
     * slides every later argument one slot left. While every value came from
     * the route or a default, that shift is exactly what the framework always
     * did and applications are written against it. The moment a RESOLVER's
     * value is in the list, the shift stops being a quirk and becomes a
     * misdelivery: a value computed for one parameter arrives at another, which
     * on a route-model-bound handler means an authorized entity landing in a
     * slot declared for something else, or the raw identifier from the URL
     * landing where the entity was declared.
     *
     * So delivery mode is chosen, not fixed:
     *
     *  - POSITIONAL — a plain list, byte-identical to the pre-chain kernel —
     *    whenever no claimed value entered the list, or when nothing was
     *    omitted before a value that did. In the second case the two modes
     *    produce the same call anyway, so the cheaper one is used.
     *  - BY NAME — the same values, spread as named arguments — when a claimed
     *    value is in the list AND an omitted parameter precedes some value.
     *    Every value then lands on the parameter it was computed for, and the
     *    omitted parameter raises an `ArgumentCountError` naming itself rather
     *    than silently receiving its successor's value.
     *
     * The invariant that makes this safe to reason about: WHEN NO CLAIMED VALUE
     * REACHES THE ARGUMENT LIST, BOTH THE VALUES AND THE DELIVERY MODE ARE THE
     * ONES THE KERNEL USED BEFORE THE RESOLVER CHAIN EXISTED. That covers every
     * application with no resolver registered, and every unbound route in an
     * application that has one.
     *
     * With no resolver registered the chain is skipped on a single array
     * comparison and this reduces to the route-parameter / default ladder the
     * kernel has always applied.
     *
     * @param class-string $class
     * @param array<string, string> $routeParams
     *
     * @return array<array-key, mixed> A list for positional delivery; string-keyed by
     *                                 parameter name when the call is made by name.
     */
    private function resolveHandlerArguments(
        string $class,
        string $method,
        ServerRequestInterface $request,
        array $routeParams,
    ): array {
        $cacheKey = $class . '::' . $method;
        $descriptor = $this->handlerDescriptors[$cacheKey] ??= $this->describeHandler($class, $method);

        if ($descriptor->usesArrayParams) {
            return $descriptor->wantsRequest ? [$request, $routeParams] : [$routeParams];
        }

        // No resolver registered: skip the chain entirely. One array test, no
        // call, no allocation — the pre-change code path, byte for byte.
        $claimed = $this->argumentResolvers->resolvers === []
            ? []
            : $this->argumentResolvers->resolveArguments($descriptor->signature, $request, $routeParams);

        /** @var array<string, mixed> $filled Parameter name => value, in declaration order. */
        $filled = [];

        // Set once a parameter is omitted; upgraded to $misaligned as soon as a
        // value is placed after the omission, because only then does a slot stop
        // corresponding to the parameter it was computed for.
        $omitted = false;
        $misaligned = false;

        // Whether any value in $filled came from the chain. The delivery-mode
        // decision hangs on this and not on `$claimed !== []`: a resolver that
        // claims a name this handler does not declare, or whose claim loses the
        // name to another resolver's, must not change how the handler is called.
        $resolved = false;

        foreach ($descriptor->signature->parameters as $parameter) {
            $name = $parameter->name;

            // array_key_exists, not isset: a resolver may legitimately claim null.
            // The spread rebuild, rather than `$filled[$name] =`, is what keeps a
            // claimed value's `mixed` type out of an assignment Psalm cannot check.
            if (array_key_exists($name, $claimed)) {
                $filled = [...$filled, $name => $claimed[$name]];
                $resolved = true;
            } elseif (isset($routeParams[$name])) {
                $filled = [...$filled, $name => $routeParams[$name]];
            } elseif ($parameter->hasDefault) {
                $filled = [...$filled, $name => $parameter->default];
            } else {
                // Nothing anywhere can fill this parameter. It is left out and the
                // loop continues, which is what the kernel has always done; the
                // flag only decides how the remaining values are DELIVERED.
                $omitted = true;

                continue;
            }

            // Read after the chain, so it reflects earlier iterations only: a
            // value has just been placed behind an omission.
            if ($omitted) {
                $misaligned = true;
            }
        }

        $leading = $descriptor->wantsRequest ? [$request] : [];

        // Named delivery. The request stays positional at index 0 — it is not in
        // the signature, so it can never collide with a parameter name — and PHP
        // requires integer keys ahead of string ones, which this ordering gives.
        if ($misaligned && $resolved) {
            return [...$leading, ...$filled];
        }

        return [...$leading, ...array_values($filled)];
    }

    /**
     * Reflect one handler into the plain data the invocation path needs.
     *
     * Called once per distinct `Class::method` and memoised for the process
     * lifetime, so steady-state per-request reflection cost is zero. The result
     * is deliberately var_export()-able — no closures, no reflection objects —
     * so a build step can precompute the whole map into the framework cache.
     *
     * @param class-string $class
     */
    private function describeHandler(string $class, string $method): HandlerDescriptor
    {
        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (ReflectionException) {
            // Reflection failed; fall back to legacy array-passing with $request,
            // exactly as before.
            return new HandlerDescriptor(true, true, new HandlerSignature($class, $method, []));
        }

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

        // Legacy array-passing: if the param right after $request (or the very
        // first when there is no $request) is typed `array`, hand over the raw
        // $routeParams.
        $arrayParamIndex = $wantsRequest ? 1 : 0;
        $usesArray = false;
        if (isset($params[$arrayParamIndex])) {
            $type = $params[$arrayParamIndex]->getType();
            $usesArray = $type instanceof ReflectionNamedType && $type->getName() === 'array';
        }

        $descriptors = [];
        foreach ($params as $i => $param) {
            if ($wantsRequest && $i === 0) {
                continue; // Skip $request; the kernel supplies it.
            }

            // Null for an untyped parameter and for a union or intersection:
            // neither is a single class, so a resolver that can only compare one
            // name must not gamble on a TypeError.
            //
            // The whole declaration is recorded alongside it, in disjunctive
            // normal form. A union used to arrive here as a null type and reach a
            // resolver as "no type at all", which is how `show(Post|string $post)`
            // on a bound route came to receive the raw URL string in a slot that
            // said an entity was acceptable.
            $type = $param->getType();
            $typeName = null;
            $isBuiltin = false;

            if ($type instanceof ReflectionNamedType) {
                $typeName = $type->getName();
                $isBuiltin = $type->isBuiltin();
            }

            $descriptors[] = new HandlerParameter(
                name: $param->getName(),
                type: $typeName,
                builtin: $isBuiltin,
                hasDefault: $param->isDefaultValueAvailable(),
                default: $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                typeAlternatives: self::typeAlternatives($type),
            );
        }

        return new HandlerDescriptor(
            $wantsRequest,
            $usesArray,
            new HandlerSignature($class, $method, $descriptors),
        );
    }

    /**
     * Flatten a reflected type into the disjunctive normal form
     * {@see HandlerParameter::$typeAlternatives} carries.
     *
     * Plain strings, so the descriptor stays var_export()-able and a build step
     * can precompute it. PHP 8.2's DNF types make a union member an intersection
     * — `(Countable&Traversable)|null` — so the outer list is alternatives and
     * each inner list is a conjunction; collapsing the two would turn "all of
     * these" into "any of these" and claim a parameter the handler would refuse.
     *
     * @return list<list<string>>
     */
    private static function typeAlternatives(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [[$type->getName()]];
        }

        if ($type instanceof ReflectionIntersectionType) {
            return [self::namedTypeNames($type->getTypes())];
        }

        if (!$type instanceof ReflectionUnionType) {
            return [];
        }

        $alternatives = [];

        foreach ($type->getTypes() as $member) {
            $alternatives[] = $member instanceof ReflectionIntersectionType
                ? self::namedTypeNames($member->getTypes())
                : [$member->getName()];
        }

        return $alternatives;
    }

    /**
     * @param array<ReflectionType> $types
     *
     * @return list<string>
     */
    private static function namedTypeNames(array $types): array
    {
        $names = [];

        foreach ($types as $type) {
            if ($type instanceof ReflectionNamedType) {
                $names[] = $type->getName();
            }
        }

        return $names;
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
     * Every loaded extension that implements
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
     *
     * The loop that did this lived here and passed
     * `$this->container` — the REAL one — so `shutdown()` was
     * a fifth lifecycle hook, undeclared as such, that handed
     * every extension at every tier the container the other
     * four had spent their effort scoping. It belongs with the
     * other four, in {@see ExtensionBootstrap::shutdown()},
     * which scopes it like the rest.
     */
    public function shutdown(): void
    {
        if ($this->extensionBootstrap !== null) {
            $this->extensionBootstrap->shutdown($this->container);

            // Reset the boot phase so a re-boot re-runs extension boot (without
            // re-registering — see ExtensionBootstrap::resetLifecycle()).
            $this->extensionBootstrap->resetLifecycle();
        }

        // Restore the pre-boot router/middleware baseline so a re-boot rebuilds
        // from a clean slate instead of stacking duplicates. Routes/middleware
        // registered before the first boot() are part of the snapshot and thus
        // survive; wiring-, extension-, and route-file-registered ones are
        // re-added by the next boot().
        if ($this->routerSnapshot !== null) {
            $this->router->restoreFromSnapshot($this->routerSnapshot);
            $this->routerSnapshot = null;
        }

        if ($this->middlewareSnapshot !== null) {
            $this->middleware->restoreFromSnapshot($this->middlewareSnapshot);
            $this->middlewareSnapshot = null;
        }

        // Same invariant for the two boot-populated seams. Without this a worker
        // that recycles would stack a second copy of every argument resolver and
        // every post-routing middleware, resolving each bound model twice and
        // running each authorization check twice.
        if ($this->argumentResolverSnapshot !== null) {
            $this->argumentResolverLifecycle->restore($this->argumentResolverSnapshot);
            $this->argumentResolverSnapshot = null;
        }

        if ($this->postRoutingSnapshot !== null) {
            $this->postRouting->restoreFromSnapshot($this->postRoutingSnapshot);
            $this->postRoutingSnapshot = null;
        }

        // Reset boot-derived per-process state so the next boot() rebuilds it.
        $this->dispatchHandlerSet = false;
        $this->exceptionHandler = null;
        $this->routeContext = null;
        $this->metricsRegistry = null;
        $this->bootProfile = null;

        $this->booted = false;
    }
}
