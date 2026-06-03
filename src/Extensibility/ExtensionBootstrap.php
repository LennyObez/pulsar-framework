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
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Extensibility\Internal\ScopedContainerProxy;
use Pulsar\Extensibility\Internal\ScopedRouterProxy;
use Pulsar\Extensibility\Internal\ServiceRestrictionMap;
use Pulsar\Routing\RouterInterface;
use ReflectionClass;
use Throwable;

use function array_filter;
use function array_keys;
use function array_values;
use function in_array;
use function sprintf;

/**
 * Bootstraps extensions into the kernel lifecycle.
 *
 * Manages the four-phase extension lifecycle (all phases respect the
 * dependency-resolved extension order from ExtensionLoader):
 * 1. Register phase: All extensions register their services
 * 2. PreBoot phase: Extensions implementing PreBootExtensionInterface
 * 3. Boot phase: All extensions boot (in dependency order)
 * 4. PostBoot phase: Extensions implementing PostBootExtensionInterface
 *
 * When a CapabilityPolicy is configured, container and router access
 * is scoped per extension based on its effective trust tier.
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
     * When non-null, only extensions whose names appear in this list are loaded.
     * When null (default), all discovered extensions are loaded.
     *
     * @var list<string>|null
     */
    private ?array $enabledFilter = null;

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

        // Apply enabled filter: skip extensions not in the allowed list
        if ($this->enabledFilter !== null) {
            $enabledFilter = $this->enabledFilter;
            $manifests = array_values(array_filter(
                $manifests,
                static fn(ExtensionManifest $m): bool => in_array($m->name, $enabledFilter, true),
            ));
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
     * Get warnings produced during the last loadFromPaths() call.
     *
     * @return list<string>
     */
    public function getLoadWarnings(): array
    {
        return $this->loadWarnings;
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
                // F3.4: prefer container resolution so service
                // providers can declare constructor dependencies
                // (logger, config, clock). The previous `new
                // $providerClass()` direct call hardcoded the
                // zero-argument-constructor convention into the
                // bootstrap layer. We fall back to direct instantiation
                // when the container cannot resolve the class — that
                // covers extensions whose providers genuinely take
                // no dependencies + the bootstrap path that runs
                // before the container is fully wired.
                foreach ($extension->providers() as $providerClass) {
                    $provider = self::instantiateProvider($providerClass, $container);

                    // Defer registration for deferred providers
                    if ($provider instanceof DeferredServiceProviderInterface && $provider->isDeferred()) {
                        if ($container instanceof AdvancedContainerInterface) {
                            $container->registerDeferredProvider($provider);
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
            $scopedRouter = $this->scopeRouter($router, $name);

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
     * Instantiate an extension service provider via the container.
     *
     * ARCH-EXT-02 (external audit): the previous implementation fell back to
     * `new $providerClass()` whenever container resolution failed. That
     * fallback let providers escape the DI graph silently — a misconfigured
     * provider that should have raised a binding error during boot was
     * instead constructed with default state, masking the wiring bug until
     * later (often in production). The extension-first contract requires
     * every provider to flow through the container; if the container cannot
     * resolve the class, the only acceptable answer is a clear failure that
     * points the operator at the missing binding.
     *
     * The Pulsar container autowires zero-argument constructors out of the
     * box, so existing providers continue to work unchanged. Providers with
     * constructor dependencies must be bound explicitly in their extension's
     * own composition root (or made autowire-friendly).
     *
     * @param class-string<ServiceProviderInterface> $providerClass
     *
     * @throws ExtensionException when the container cannot resolve the
     *                           provider class
     */
    private static function instantiateProvider(
        string $providerClass,
        ContainerInterface $container,
    ): ServiceProviderInterface {
        try {
            $resolved = $container->get($providerClass);
        } catch (Throwable $containerError) {
            // F3.4: fall back to direct instantiation for providers that declare
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
            throw new ExtensionException(
                sprintf(
                    'Extension service provider "%s" resolved to %s, which does not implement %s. '
                    . 'Check the container binding for this provider class.',
                    $providerClass,
                    get_debug_type($resolved),
                    ServiceProviderInterface::class,
                ),
            );
        }

        return $resolved;
    }

    /**
     * Scope a container for an extension based on its effective trust tier.
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

        $restrictionMap = $this->serviceRestrictionMap ?? ServiceRestrictionMap::defaults();
        $additionalCapabilities = $this->trustedExtensionsConfig?->additionalCapabilities($extensionName) ?? [];

        return new ScopedContainerProxy(
            $container,
            $effectiveTier,
            $this->capabilityPolicy,
            $restrictionMap,
            $additionalCapabilities,
        );
    }

    /**
     * Scope a router for an extension based on its effective trust tier.
     *
     * Returns the original router when no capability policy is configured
     * or when the extension has Core effective tier.
     */
    private function scopeRouter(RouterInterface $router, string $extensionName): RouterInterface
    {
        if ($this->capabilityPolicy === null) {
            return $router;
        }

        $effectiveTier = $this->resolveEffectiveTier($extensionName);

        // Core tier bypasses proxy entirely
        if ($effectiveTier === TrustTier::Core) {
            return $router;
        }

        return new ScopedRouterProxy(
            $router,
            $effectiveTier,
            $extensionName,
            $this->capabilityPolicy,
        );
    }

    /**
     * Resolve the effective trust tier for an extension.
     */
    private function resolveEffectiveTier(string $extensionName): TrustTier
    {
        $manifest = $this->registry->getManifest($extensionName);
        $requested = $manifest->requestedTrustTier;

        if ($this->trustedExtensionsConfig !== null) {
            return $this->trustedExtensionsConfig->effectiveTier($extensionName, $requested);
        }

        return $requested;
    }
}
