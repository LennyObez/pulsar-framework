<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use NoDiscard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Api;
use Pulsar\Config\TrustedExtensionsConfig;
use Pulsar\Container\AdvancedContainerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Provider\DeferredServiceProviderInterface;
use Pulsar\Extensibility\Exception\CapabilityDeniedException;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\Internal\ExtensionSurfaces;
use Pulsar\Extensibility\Internal\ScopedContainerProxy;
use Pulsar\Extensibility\Internal\ScopedDeferredProvider;
use Pulsar\Extensibility\Internal\ServiceRestrictionMap;
use Pulsar\Routing\RouterInterface;
use ReflectionClass;
use Throwable;

use function array_keys;
use function count;
use function error_log;
use function get_debug_type;
use function implode;
use function in_array;
use function is_object;
use function sprintf;

/**
 * Bootstraps extensions into the kernel lifecycle.
 *
 * Manages the five-phase extension lifecycle (all phases respect the
 * dependency-resolved extension order from ExtensionLoader):
 * 1. Register phase: All extensions register their services
 * 2. PreBoot phase: Extensions implementing PreBootExtensionInterface
 * 3. Boot phase: All extensions boot (in dependency order)
 * 4. PostBoot phase: Extensions implementing PostBootExtensionInterface
 * 5. Shutdown phase: Extensions implementing ShutdownAwareExtensionInterface
 *
 * The fifth used to be a loop in {@see \Pulsar\Core\Kernel::shutdown()} that
 * called `$extension->shutdown($container)` with the REAL container, so four
 * phases scoped and the one nobody counted did not.
 *
 * When a CapabilityPolicy is configured, container and router access is scoped
 * per extension based on its EFFECTIVE trust tier — min(tier requested by the
 * manifest, tier granted by the host allow-list), with Community for anything
 * the host has not listed. The manifest's own claim is never a grant; see
 * {@see self::resolveEffectiveTier()}.
 * @api
 */
#[Api(since: '1.0.0')]
final class ExtensionBootstrap
{
    public private(set) bool $registered = false;
    public private(set) bool $booted = false;
    public ?CapabilityPolicy $capabilityPolicy = null;
    public ?ServiceRestrictionMap $serviceRestrictionMap = null;
    public ?TrustedExtensionsConfig $trustedExtensionsConfig = null;

    /** @var list<string> */
    private array $loadWarnings = [];

    /**
     * One scope per extension, for the life of the bootstrap.
     *
     * @var array<string, ScopedContainerProxy>
     */
    private array $scopes = [];

    /**
     * What every loaded extension publishes, memoised; see {@see self::surfaces()}.
     */
    private ?ExtensionSurfaces $surfaces = null;

    /** How many manifests {@see self::$surfaces} was built from. */
    private int $surfacesFor = -1;

    /**
     * Names of extensions found on disk but excluded by the enabled filter,
     * from the last loadFromPaths() call. Distinct from a load failure: these
     * were deliberately turned off via extensions.enabled. Surfaced so boot
     * diagnostics can relate a lingering config/<ext>.php to its off switch.
     *
     * @var list<string>
     */
    private array $disabledByFilter = [];

    /**
     * When non-null, ONLY extensions whose names appear in this list are loaded
     * (an exclusive allowlist — the operator's full-manual switch). When null
     * (default), the kind-based default posture applies: every discovered
     * extension loads except bundled products, which stay off until opted in via
     * {@see self::$enabledProducts}.
     *
     * @var list<string>|null
     */
    private ?array $enabledFilter = null;

    /**
     * Bundled application/product extensions ({@see ExtensionKind::Product}) to
     * turn ON in the default posture. Additive opt-in, consulted only when no
     * exclusive {@see self::$enabledFilter} allowlist is set. Null/empty means no
     * products load by default. Maps to `extensions.enabled_products`.
     *
     * @var list<string>|null
     */
    private ?array $enabledProducts = null;

    public function __construct(
        public readonly ExtensionRegistry $registry,
        public readonly ExtensionLoader $loader,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Create a bootstrap instance with default loader.
     */
    #[NoDiscard]
    public static function create(): self
    {
        return new self(new ExtensionRegistry(), new ExtensionLoader());
    }

    /**
     * Restrict which extensions are loaded by name.
     *
     * When set, only extensions whose manifest name appears in the given
     * list will proceed past discovery. Extensions not in the list are
     * silently skipped. Pass null to clear the filter and load all.
     *
     * @param list<string>|null $names Extension names (e.g., ['pulsar/cms', 'pulsar/forum'])
     */
    public function setEnabledFilter(?array $names): void
    {
        $this->enabledFilter = $names;
    }

    /**
     * Turn specific bundled product extensions ON in the default posture.
     *
     * Bundled application/products (manifest `kind: product`) are off by default
     * so a regulated app never inherits a forum, CMS, or PII collector it did not
     * ask for. Naming a product here loads it while leaving the rest of the
     * default posture (all infrastructure + the app's own extensions) intact.
     *
     * Consulted only when no exclusive allowlist is set via
     * {@see self::setEnabledFilter()}; an allowlist already names everything that
     * loads, products included. Pass null/[] to load no products by default.
     *
     * @param list<string>|null $names Product extension names (e.g., ['pulsar/forum'])
     */
    public function setEnabledProducts(?array $names): void
    {
        $this->enabledProducts = $names;
    }

    /**
     * Discover and load extensions from paths.
     *
     * Each path may be:
     *   - A parent directory to scan for extensions (e.g. `extensions/`)
     *   - An individual extension directory containing a `pulsar.json`
     *
     * Individual extensions that fail validation or instantiation are skipped
     * with a warning logged rather than aborting the entire loading process.
     *
     * When an enabled filter is set via setEnabledFilter(), only manifests
     * whose name appears in the filter list proceed past discovery.
     *
     * @param list<string> $paths Directories to scan for extensions
     */
    public function loadFromPaths(array $paths): void
    {
        $this->loadWarnings = [];
        $this->disabledByFilter = [];

        try {
            $manifests = $this->loader->discover($paths);
        } catch (Throwable $e) {
            // Discovery failure (e.g., invalid JSON in a manifest) should not
            // abort the entire loading process. Record the warning and attempt
            // per-directory discovery with individual error handling.
            $this->loadWarnings[] = sprintf('Discovery error: %s', $e->getMessage());
            $this->logger->warning('Extension discovery failed: ' . $e->getMessage());
            $manifests = $this->discoverWithFallback($paths);
        }

        // Decide which discovered manifests actually load. Two orthogonal
        // controls, checked in order; an extension turned off either way is a
        // deliberate decision, but a SILENT one is a foot-gun — it reads
        // identically to a missing extension — so every exclusion is recorded as
        // a warning that getLoadWarnings()/disabledByConfig() and the log expose.
        if ($this->enabledFilter !== null) {
            // 1. Exclusive allowlist: ONLY the named extensions load — products,
            //    infrastructure, and the app's own extensions alike. The
            //    operator's full-manual switch; kind is not consulted.
            $allow = $this->enabledFilter;
            [$manifests, $excluded] = self::partitionManifests(
                $manifests,
                static fn(ExtensionManifest $m): bool => in_array($m->name, $allow, true),
            );
            $this->recordDisabled(
                $excluded,
                'Extensions present on disk but disabled by config (extensions.enabled): %s',
            );
        } else {
            // 2. Default posture: every discovered extension loads EXCEPT bundled
            //    products (manifest `kind: product`), which stay off until named
            //    in extensions.enabled_products. A regulated app must not inherit
            //    a forum or a PII collector it never asked for, while its own
            //    first-party extensions still load without ceremony.
            $optIn = $this->enabledProducts ?? [];
            [$manifests, $excluded] = self::partitionManifests(
                $manifests,
                static fn(ExtensionManifest $m): bool =>
                    $m->kind->loadsByDefault() || in_array($m->name, $optIn, true),
            );
            $this->recordDisabled(
                $excluded,
                'Bundled product extensions off by default '
                . '(opt in via extensions.enabled_products): %s',
            );
        }

        // Register PSR-4 autoloading for the discovered extensions before any of
        // their classes are referenced. Extensions are not baked into the root
        // composer.json autoload (ADR-0004: no privileged built-in access), so
        // this is what makes `validateExtensionClass()` / `instantiate()` below —
        // and the extensions themselves — resolvable.
        $autoloader = new ExtensionAutoloader();
        foreach ($manifests as $manifest) {
            $autoloader->addPsr4($manifest->autoloadMap());
        }
        $autoloader->register();

        // Validate each manifest individually: skip failures, don't abort all
        $validManifests = [];

        foreach ($manifests as $manifest) {
            try {
                $this->loader->validateCompatibility($manifest);
            } catch (Throwable $e) {
                $warning = sprintf(
                    'Skipping extension "%s": incompatible version: %s',
                    $manifest->name,
                    $e->getMessage(),
                );
                $this->loadWarnings[] = $warning;
                $this->logger->warning($warning);

                continue;
            }

            try {
                $this->loader->validateExtensionClass($manifest);
            } catch (Throwable $e) {
                $warning = sprintf(
                    'Skipping extension "%s": class not found: %s',
                    $manifest->name,
                    $e->getMessage(),
                );
                $this->loadWarnings[] = $warning;
                $this->logger->warning($warning);

                continue;
            }

            $validManifests[] = $manifest;
        }

        $sorted = $this->loader->resolveDependencies($validManifests);

        // Instantiate each extension individually: skip failures
        foreach ($sorted as $manifest) {
            try {
                $extension = $this->loader->instantiate($manifest);
                $this->registry->add($extension, $manifest, ExtensionLifecycle::Validated);
            } catch (Throwable $e) {
                $warning = sprintf(
                    'Skipping extension "%s": instantiation failed: %s',
                    $manifest->name,
                    $e->getMessage(),
                );
                $this->loadWarnings[] = $warning;
                $this->logger->warning($warning);
            }
        }
    }

    /**
     * Fallback discovery that processes each path individually, skipping
     * paths that cause parse errors instead of aborting all discovery.
     *
     * @param list<string> $paths
     * @return list<ExtensionManifest>
     */
    private function discoverWithFallback(array $paths): array
    {
        $manifests = [];

        foreach ($paths as $path) {
            try {
                $discovered = $this->loader->discover([$path]);
                $manifests = [...$manifests, ...$discovered];
            } catch (Throwable $e) {
                $warning = sprintf('Skipping path "%s": %s', $path, $e->getMessage());
                $this->loadWarnings[] = $warning;
                $this->logger->warning($warning);
            }
        }

        return $manifests;
    }

    /**
     * Split discovered manifests into those that load and the names of those
     * excluded, by a keep predicate. Order is preserved.
     *
     * @param list<ExtensionManifest> $manifests
     * @param callable(ExtensionManifest): bool $keep
     * @return array{0: list<ExtensionManifest>, 1: list<string>}
     */
    private static function partitionManifests(array $manifests, callable $keep): array
    {
        $kept = [];
        $excluded = [];

        foreach ($manifests as $manifest) {
            if ($keep($manifest)) {
                $kept[] = $manifest;
            } else {
                $excluded[] = $manifest->name;
            }
        }

        return [$kept, $excluded];
    }

    /**
     * Record the names of extensions excluded by an enable control so boot
     * diagnostics can relate a lingering config/<ext>.php to its off switch. The
     * message template takes a single %s for the comma-joined names.
     *
     * @param list<string> $names
     */
    private function recordDisabled(array $names, string $messageTemplate): void
    {
        if ($names === []) {
            return;
        }

        $this->disabledByFilter = [...$this->disabledByFilter, ...$names];

        $warning = sprintf($messageTemplate, implode(', ', $names));
        $this->loadWarnings[] = $warning;
        $this->logger->info($warning);
    }

    /**
     * Get warnings produced during the last loadFromPaths() call.
     *
     * @return list<string>
     */
    public function getLoadWarnings(): array
    {
        return $this->loadWarnings;
    }

    /**
     * Names of extensions found on disk but turned off via extensions.enabled
     * during the last loadFromPaths() call.
     *
     * @return list<string>
     */
    public function disabledByConfig(): array
    {
        return $this->disabledByFilter;
    }

    /**
     * Get all loaded extension manifests.
     *
     * @return array<string, ExtensionManifest>
     */
    public function getManifests(): array
    {
        return $this->registry->allManifests();
    }

    /**
     * Add an extension manually (for testing or programmatic registration).
     */
    public function addExtension(ExtensionInterface $extension, ExtensionManifest $manifest): void
    {
        $this->registry->add($extension, $manifest, ExtensionLifecycle::Validated);
    }

    /**
     * Register phase: Call register() on all extensions.
     *
     * @throws ExtensionException If registration fails
     */
    public function register(ContainerInterface $container): void
    {
        if ($this->registered) {
            return;
        }

        foreach ($this->registry->all() as $name => $extension) {
            $state = $this->registry->getState($name);

            if (!$state->canRegister()) {
                continue;
            }

            $scopedContainer = $this->scopeContainer($container, $name);

            try {
                // Prefer container resolution so service providers can
                // declare constructor dependencies (logger, config,
                // clock); calling `new $providerClass()` directly would
                // hardcode a zero-argument-constructor convention into
                // the bootstrap layer. Direct instantiation stays as a
                // fallback for when the container cannot resolve the
                // class — providers that genuinely take no dependencies,
                // and the bootstrap path that runs before the container
                // is fully wired.
                foreach ($extension->providers() as $providerClass) {
                    $provider = self::instantiateProvider($providerClass, $container, $scopedContainer);

                    // Defer registration for deferred providers
                    if ($provider instanceof DeferredServiceProviderInterface && $provider->isDeferred()) {
                        if ($container instanceof AdvancedContainerInterface) {
                            // The registry calls register() with the container
                            // that is resolving — the real one — at whatever
                            // point the deferred id is first asked for, long
                            // after this scope would otherwise have expired.
                            // Bind the scope to the provider now so the
                            // deferred call is scoped like the eager one below.
                            $container->registerDeferredProvider(
                                ScopedDeferredProvider::wrap($provider, $scopedContainer),
                            );
                        }
                        continue;
                    }

                    $provider->register($scopedContainer);
                }

                // Then call extension's own register method
                $extension->register($scopedContainer);

                $this->registry->setState($name, ExtensionLifecycle::Registered);
            } catch (Throwable $e) {
                $this->registry->setState($name, ExtensionLifecycle::Failed);
                throw ExtensionException::registrationFailed($name, $e->getMessage());
            }
        }

        // Expose the registry in the container so composition root services
        // (migration wiring, introspection, health checks) can access extension metadata.
        $container->instance(ExtensionRegistry::class, $this->registry);

        $this->registered = true;
    }

    /**
     * Boot phase: preBoot → boot → postBoot on all extensions.
     *
     * Each sub-phase iterates ALL extensions in dependency order before
     * advancing to the next phase. This guarantees that preBoot completes
     * for every extension before any boot() runs.
     *
     * @throws ExtensionException If booting fails
     */
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        if ($this->booted) {
            return;
        }

        if (!$this->registered) {
            throw ExtensionException::bootBeforeRegister();
        }

        // Phase 2: preBoot (optional; only PreBootExtensionInterface implementors)
        foreach ($this->registry->all() as $name => $extension) {
            if (!$extension instanceof PreBootExtensionInterface) {
                continue;
            }

            $state = $this->registry->getState($name);

            if (!$state->canBoot()) {
                continue;
            }

            $scopedContainer = $this->scopeContainer($container, $name);

            try {
                $extension->preBoot($scopedContainer);
            } catch (Throwable $e) {
                $this->registry->setState($name, ExtensionLifecycle::Failed);
                throw ExtensionException::bootFailed($name, $e->getMessage());
            }
        }

        // Phase 3: boot (all extensions)
        foreach ($this->registry->all() as $name => $extension) {
            $state = $this->registry->getState($name);

            if (!$state->canBoot()) {
                continue;
            }

            $scopedContainer = $this->scopeContainer($container, $name);
            $scopedRouter = $this->scopeRouter($container, $router, $name);

            try {
                $extension->boot($scopedContainer, $scopedRouter);
                $this->registry->setState($name, ExtensionLifecycle::Booted);
            } catch (Throwable $e) {
                $this->registry->setState($name, ExtensionLifecycle::Failed);
                throw ExtensionException::bootFailed($name, $e->getMessage());
            }
        }

        // Phase 4: postBoot (optional; only PostBootExtensionInterface implementors)
        foreach ($this->registry->all() as $name => $extension) {
            if (!$extension instanceof PostBootExtensionInterface) {
                continue;
            }

            $scopedContainer = $this->scopeContainer($container, $name);

            try {
                $extension->postBoot($scopedContainer);
            } catch (Throwable $e) {
                $this->registry->setState($name, ExtensionLifecycle::Failed);
                throw ExtensionException::bootFailed($name, $e->getMessage());
            }
        }

        $this->booted = true;
    }

    /**
     * Shutdown phase: release what each extension holds, through its own scope.
     *
     * The fifth lifecycle hook, and the one that was not treated as one. The
     * loop lived in {@see \Pulsar\Core\Kernel::shutdown()} and called
     * `$extension->shutdown($container)` with the REAL container — so an
     * extension that could not obtain it in `register()`, `preBoot()`, `boot()`
     * or `postBoot()` was handed it on the way out, at every tier, by the one
     * phase nobody had counted. It is here now, beside the four that scope, and
     * it scopes the same way.
     *
     * Errors are caught and reported but never block the shutdown of the rest:
     * leaving one extension stuck would prevent the others from cleaning up at
     * all. That includes a capability denial — an extension's `shutdown()` that
     * reaches for something its tier refuses fails that extension's cleanup and
     * no one else's.
     *
     * Reported to the logger AND to `error_log`, which is what the Kernel loop
     * did. A bootstrap constructed without a logger gets a `NullLogger`, and a
     * shutdown that fails silently in a recycled worker is how a leaked
     * connection pool becomes a mystery.
     */
    public function shutdown(ContainerInterface $container): void
    {
        foreach ($this->registry->all() as $name => $extension) {
            if (!$extension instanceof ShutdownAwareExtensionInterface) {
                continue;
            }

            try {
                $extension->shutdown($this->scopeContainer($container, $name));
            } catch (Throwable $e) {
                $message = sprintf('Extension shutdown failed for "%s": %s', $name, $e->getMessage());
                $this->logger->error($message);
                error_log('[Pulsar] ' . $message);
            }
        }
    }

    /**
     * Reset the boot lifecycle after a kernel shutdown so a subsequent boot()
     * re-runs only the boot phase for already-registered extensions.
     *
     * Booted extensions are returned to the Registered state (which canBoot()
     * accepts) — deliberately NOT to Validated — so register() does not run a
     * second time; re-registration is unsafe without a contract that extension
     * register() is idempotent. The registered flag stays true. Failed and
     * not-yet-booted extensions are left untouched.
     */
    public function resetLifecycle(): void
    {
        $this->booted = false;

        foreach (array_keys($this->registry->all()) as $name) {
            if ($this->registry->getState($name)->isBooted()) {
                $this->registry->setState($name, ExtensionLifecycle::Registered);
            }
        }
    }

    /**
     * Get all commands provided by extensions.
     *
     * @return list<string> Command class names
     */
    public function getCommands(): array
    {
        $commands = [];

        foreach ($this->registry->allManifests() as $manifest) {
            if ($manifest->provides->hasCommands()) {
                $commands = [...$commands, ...$manifest->provides->commands];
            }
        }

        return $commands;
    }

    /**
     * Build one extension-declared CLI command, through the scope of the
     * extension that declared it — and charge it `CommandRegister`.
     *
     * The console entry point used to do this itself:
     * `$kernel->container()->get($commandClass)`, on the real container. A
     * command class is an unvetted class name like a route handler, so
     * `__construct(ContainerInterface $c)` on one delivered the unscoped
     * container to extension code, in a process that runs as whoever runs
     * `pulsar`. It also meant `CommandRegister` — declared in
     * {@see ExtensionCapability}, granted by {@see CapabilityPolicy} down to
     * Community, and named in ADR-0023's table — had no enforcement site
     * anywhere in the framework. This is the site.
     *
     * Core keeps the container path: it has no scope, by definition.
     *
     * @param class-string $commandClass
     *
     * @throws CapabilityDeniedException When the declaring extension's tier does not hold CommandRegister
     * @throws ExtensionException When the class is not declared by any loaded extension
     * @throws Throwable When the command's own construction fails
     */
    #[NoDiscard]
    public function buildCommand(string $commandClass, ContainerInterface $container): object
    {
        $owner = $this->commandOwner($commandClass);

        if ($owner === null) {
            throw new ExtensionException(sprintf(
                'Command "%s" is not declared in the `provides.commands` list of any loaded extension, '
                . 'so there is no extension scope to build it in.',
                $commandClass,
            ));
        }

        $scope = $this->scopeContainer($container, $owner);

        if (!$scope instanceof ScopedContainerProxy) {
            // Core, or no policy configured. Straight to the container, which is
            // what `bin/pulsar` did before this method existed.
            //
            // Not `has() ? get() : newInstance()`: `Container::has()` reports
            // false for an unbound-but-instantiable concrete and
            // `Container::get()` autowires exactly those, so the guard sent
            // every command nothing has bound — which is every command — down
            // the no-argument branch. `pulsar/mcp-server`'s McpServeCommand
            // takes three constructor arguments and got none, and the console
            // reported it as a command-registration failure at every boot.
            return self::asObject($container->get($commandClass), $commandClass);
        }

        $tier = $this->resolveEffectiveTier($owner);
        $granted = $this->capabilityPolicy?->allows($tier, ExtensionCapability::CommandRegister) === true
            || in_array(
                ExtensionCapability::CommandRegister,
                $this->trustedExtensionsConfig?->additionalCapabilities($owner) ?? [],
                true,
            );

        if (!$granted) {
            throw CapabilityDeniedException::forCapability($tier, ExtensionCapability::CommandRegister);
        }

        return $scope->construct($commandClass);
    }

    /**
     * The extension whose manifest declares this command class, if any.
     */
    private function commandOwner(string $commandClass): ?string
    {
        foreach ($this->registry->allManifests() as $name => $manifest) {
            if (in_array($commandClass, $manifest->provides->commands, true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @throws ExtensionException
     */
    private static function asObject(mixed $resolved, string $id): object
    {
        if (!is_object($resolved)) {
            throw new ExtensionException(sprintf(
                'Command "%s" resolved to %s rather than an object.',
                $id,
                get_debug_type($resolved),
            ));
        }

        return $resolved;
    }

    /**
     * Instantiate an extension service provider THROUGH THE EXTENSION'S SCOPE.
     *
     * A provider constructor is the one place where an extension chooses a
     * signature and the framework fills it. It used to be filled by the REAL
     * container, so `__construct(ContainerInterface $c)` on a provider handed a
     * Community extension the unscoped container and `__construct(MasterKey $k)`
     * handed it the master key, neither consulting a tier.
     *
     * The first repair asked the scoped container for a PREDICTION — "would
     * autowiring this exceed the tier?" — and then let the real container
     * autowire it anyway. A prediction has to be right about a constructor
     * nobody has run yet, and this one was wrong twice: it gave up after four
     * hops, and it returned early for a class that did not exist YET, which is
     * a state the extension chooses.
     *
     * Nothing is predicted now. The scope BUILDS the provider
     * ({@see ScopedContainerProxy::construct()}), resolving each constructor
     * parameter through its own `get()`, and the same for that parameter's
     * dependencies, with no depth bound and nothing to know in advance.
     *
     * Core tier and an unpolicied bootstrap keep the container path: there is
     * no scope to build through, which is what Core means.
     *
     * @param class-string<ServiceProviderInterface> $providerClass
     * @param ContainerInterface $container The real container, used only when
     *                                      the extension has no scope
     * @param ContainerInterface $scoped The extension's scope when it has one
     *
     * @throws ExtensionException when the provider class cannot be built
     * @throws CapabilityDeniedException when a constructor parameter exceeds the tier
     */
    private static function instantiateProvider(
        string $providerClass,
        ContainerInterface $container,
        ContainerInterface $scoped,
    ): ServiceProviderInterface {
        if ($scoped instanceof ScopedContainerProxy) {
            $built = $scoped->construct($providerClass);

            return $built instanceof ServiceProviderInterface
                ? $built
                : throw self::notAProvider($providerClass, $built);
        }

        try {
            /** @var mixed $resolved */
            $resolved = $container->get($providerClass);
        } catch (Throwable $containerError) {
            // Fall back to direct instantiation for providers that declare
            // no required constructor dependencies — the common case, and the
            // path that runs before the container is fully wired. An
            // autowire-friendly (zero-argument) provider must work without an
            // explicit binding, as the diagnostic below promises. A provider
            // whose constructor needs arguments cannot be autowired here, so it
            // gets the actionable error; any exception from a zero-argument
            // constructor body propagates to register()'s own handler.
            $constructor = new ReflectionClass($providerClass)->getConstructor();

            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                throw new ExtensionException(
                    sprintf(
                        'Extension service provider "%s" could not be resolved through the container: %s. '
                        . 'Bind it explicitly in your composition root or make its constructor autowire-friendly.',
                        $providerClass,
                        $containerError->getMessage(),
                    ),
                    previous: $containerError,
                );
            }

            $resolved = new $providerClass();
        }

        if (!$resolved instanceof ServiceProviderInterface) {
            throw self::notAProvider($providerClass, $resolved);
        }

        return $resolved;
    }

    #[NoDiscard]
    private static function notAProvider(string $providerClass, mixed $resolved): ExtensionException
    {
        return new ExtensionException(
            sprintf(
                'Extension service provider "%s" resolved to %s, which does not implement %s. '
                . 'Check the container binding for this provider class.',
                $providerClass,
                get_debug_type($resolved),
                ServiceProviderInterface::class,
            ),
        );
    }

    /**
     * The extension's scope, built once and kept.
     *
     * Cached per extension name for a reason beyond cost. The scope owns the
     * ledger of ids the extension registered through it
     * ({@see \Pulsar\Extensibility\Internal\ScopeRegistrations}), and the four
     * lifecycle phases each built a scope of their own — so a binding made in
     * `register()` was recorded by one object and looked for by another, and an
     * extension could not resolve its own service in `boot()`. Same extension,
     * same scope, for the life of the bootstrap.
     *
     * Returns the original container when no capability policy is configured
     * (backward compatibility) or when the extension has Core effective tier.
     */
    private function scopeContainer(ContainerInterface $container, string $extensionName): ContainerInterface
    {
        if ($this->capabilityPolicy === null) {
            return $container;
        }

        $effectiveTier = $this->resolveEffectiveTier($extensionName);

        // Core tier bypasses proxy entirely: zero overhead
        if ($effectiveTier === TrustTier::Core) {
            return $container;
        }

        $cached = $this->scopes[$extensionName] ?? null;

        if ($cached !== null) {
            return $cached;
        }

        $restrictionMap = $this->serviceRestrictionMap ?? ServiceRestrictionMap::defaults();
        $additionalCapabilities = $this->trustedExtensionsConfig?->additionalCapabilities($extensionName) ?? [];

        return $this->scopes[$extensionName] = new ScopedContainerProxy(
            $container,
            $effectiveTier,
            $this->capabilityPolicy,
            $restrictionMap,
            $extensionName,
            $additionalCapabilities,
            extensionPath: $this->registry->getManifest($extensionName)->path,
            surfaces: $this->surfaces(),
        );
    }

    /**
     * What every loaded extension publishes to the others, built once.
     *
     * Derived from the manifests rather than passed in, because the answer must
     * change when the set of loaded extensions changes and must not be
     * settable: a host that could hand the bootstrap a surface map could
     * publish an extension's private types on its behalf.
     *
     * Rebuilt when the registry has grown — `addExtension()` is public and tests
     * and embedders use it after the first scope is built — and otherwise
     * returned from the cache, so the reflection and `realpath()` work happens
     * once per boot rather than once per resolution.
     */
    private function surfaces(): ExtensionSurfaces
    {
        $manifests = $this->getManifests();
        $count = count($manifests);

        if ($this->surfaces === null || $this->surfacesFor !== $count) {
            $this->surfacesFor = $count;
            $this->surfaces = ExtensionSurfaces::fromManifests($manifests);
        }

        return $this->surfaces;
    }

    /**
     * Scope a router for an extension based on its effective trust tier.
     *
     * Built BY the container scope rather than beside it, so the router proxy
     * holds the same scope the extension's `register()` used. Without that link
     * the router had nowhere to bind a route handler's construction, and a
     * controller an extension routed to was built by the real container at
     * request time — an unvetted class name, filled from the real graph, long
     * after every capability check had finished running.
     *
     * Returns the original router when no capability policy is configured
     * or when the extension has Core effective tier.
     */
    private function scopeRouter(
        ContainerInterface $container,
        RouterInterface $router,
        string $extensionName,
    ): RouterInterface {
        $scope = $this->scopeContainer($container, $extensionName);

        if (!$scope instanceof ScopedContainerProxy) {
            return $router;
        }

        return $scope->scopedRouter($router);
    }

    /**
     * Resolve the effective trust tier for an extension.
     *
     * The manifest's `trust_tier` is a REQUEST, and an unauthenticated one: the
     * file ships inside the directory holding the code it describes, and nothing
     * in the framework verifies who wrote it — there is no signature check on an
     * extension anywhere in Pulsar. So it may only ever lower the effective
     * tier. An extension can de-privilege itself; it can never elevate itself.
     * Anything above Community is conferred by the host's allow-list in
     * config/extensions.php, which is the host's file and not the extension's.
     *
     * An absent allow-list is therefore an EMPTY allow-list, not an absent
     * check. Returning the requested tier when no {@see TrustedExtensionsConfig}
     * was attached — which is what this did — meant a manifest carrying
     * `"trust_tier": "core"` got Core, and Core bypasses both proxies: raw
     * container, raw router, for a file that granted itself the privilege. The
     * production path ({@see \Pulsar\Core\Boot\ExtensionSandbox::harden}) always
     * attaches a config, but `capabilityPolicy` is public and settable, so the
     * guarantee cannot rest on one caller remembering to set a second property.
     */
    private function resolveEffectiveTier(string $extensionName): TrustTier
    {
        $requested = $this->registry->getManifest($extensionName)->requestedTrustTier;

        $allowList = $this->trustedExtensionsConfig ?? new TrustedExtensionsConfig([]);

        return $allowList->effectiveTier($extensionName, $requested);
    }
}
