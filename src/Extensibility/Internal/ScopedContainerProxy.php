<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use NoDiscard;
use Override;
use Pulsar\Container\BindingType;
use Pulsar\Container\CallableReflector;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Exception\ContainerException;
use Pulsar\Container\Exception\NotFoundException;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Exception\CapabilityDeniedException;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\ExtensionConfigRegistry;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Routing\RouterInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

use function array_key_exists;
use function array_map;
use function array_slice;
use function basename;
use function class_exists;
use function count;
use function glob;
use function in_array;
use function interface_exists;
use function is_object;
use function is_string;
use function realpath;
use function rtrim;
use function sprintf;
use function str_replace;
use function str_starts_with;

/**
 * The scope. Every value an extension receives leaves through this class, and
 * every object an extension supplies is built by it.
 *
 * ## Why the shape changed
 *
 * The sandbox used to be a stack of filters: an allowlist of service ids, a
 * class-name check on anything bound by name, a four-hop reachability walk over
 * a constructor. Each was added to close a specific escape, and each was walked
 * around the same way — by supplying the name later, one level deeper, or
 * through a door nobody had filtered. Ten escapes were found after the round of
 * fixes that introduced the filters, and two of them were CREATED by that round:
 * {@see ScopedRouterProxy::resource()} was added and forwarded its argument
 * unprefixed, and the class-name check opened with `if (!class_exists()) return;`
 * on a class the extension itself decides when to define.
 *
 * Vetting names is a losing game because a name is a promise about an object,
 * made by whoever wrote the name. So the rule here is not "which names are
 * safe" but:
 *
 *  1. **Every value that leaves the scope is contained on the way out.** Not
 *     `get()` alone, and not one of its branches — every exit
 *     ({@see self::contain()}).
 *  2. **Everything the extension supplies is constructed THROUGH this proxy**
 *     ({@see self::construct()}). A class it binds by name, a closure it
 *     registers, a controller it routes to, a command it declares: whatever
 *     builds the thing receives this proxy, so the thing's own dependencies are
 *     scoped too, transitively, with no depth limit and nothing to predict.
 *  3. **A small closed set of ids never leaves, contained or not**
 *     ({@see self::SANDBOX_DEFEATING_SERVICES}), because containing them is
 *     meaningless.
 *
 * `SandboxSurfaceTest` walks every public method on this class and on
 * {@see ScopedRouterProxy} by reflection and requires each one to contain what
 * it returns or to be on an explicit, justified list — so the next method added
 * here cannot skip it, which is exactly how `resource()` happened.
 *
 * ## Design invariants
 *
 * - `has()` answers for THIS SCOPE, which is what PSR-11 asks of it: true when
 *   this container can return the entry, so `has()` and `get()` cannot disagree
 * - `get()` throws CapabilityDeniedException for denied services
 * - Deny-by-default for unknown services (non-Core tiers), except ids this
 *   extension registered itself ({@see ScopeRegistrations}), types it ships,
 *   and types another loaded extension publishes ({@see ExtensionSurfaces})
 * - No method on this class lets extension code obtain the unscoped container
 *   or router, by any route, at any tier below Core
 *
 * ## What this cannot promise
 *
 * `new ReflectionProperty(self::class, 'inner')->getValue($scope)` returns the
 * real container, and PHP offers no way to withhold private state from code
 * sharing the interpreter; ADR-0023 weighed process isolation and rejected it.
 * So the guarantee is bounded and stated rather than implied: this stops
 * accidental over-reach and casual abuse by code that is not trying to escape.
 * It does not stop a determined attacker who already has code execution in the
 * process. `readonly` is what remains available — an extension can read `$inner`
 * but cannot replace it — and that is worth having, not worth overselling.
 *
 * @internal Not part of the public API
 */
final readonly class ScopedContainerProxy implements ContainerInterface
{
    /**
     * Service ids that resolve to the objects this sandbox exists to mediate.
     *
     * One `get(ContainerInterface::class)` returns the container the Kernel
     * bound to itself, and every call after that goes straight to it — past the
     * restriction map, past the capability policy, past this class entirely.
     * `get(RouterInterface::class)` does the same to {@see ScopedRouterProxy}:
     * the real router registers at any path, prefix and all. Both ids were on
     * the restriction map's SAFE list, so a Community extension reached
     * `MasterKey` in two calls and `/admin` in two more, and every tier below
     * Core was decoration.
     *
     * {@see self::contain()} would now exchange either for a scoped equivalent
     * rather than hand it over, so this list is no longer load-bearing for
     * safety. It is kept because the ANSWER matters to an extension author: a
     * silent substitution invites code written against a container it never
     * actually has, while a refusal names the mistake at the line that made it.
     *
     * This is an invariant of the proxy rather than an entry in the map, because
     * the map is data: no value in a data file should be able to switch the
     * sandbox off, and the previous value did exactly that. Core tier never
     * reaches this class — it is handed the unwrapped container by design.
     */
    private const array SANDBOX_DEFEATING_SERVICES = [
        'Pulsar\Container\ContainerInterface',
        'Pulsar\Container\AdvancedContainerInterface',
        'Pulsar\Container\Container',
        'Pulsar\Routing\RouterInterface',
        'Pulsar\Routing\Router',
    ];

    /**
     * The configuration section names this extension ships, computed once.
     *
     * Read by {@see self::contain()} on every exit, so it is derived here rather
     * than per call: one `glob()` per extension per boot, against a directory
     * that does not change while the process runs.
     *
     * @var list<string>
     */
    private array $ownConfigSections;

    /**
     * The extension's directory, resolved and slash-normalised once, or null
     * when it has none (added programmatically, as tests do).
     *
     * {@see self::isOwnCode()} runs on every id deny-by-default would otherwise
     * refuse, which since `has()` started answering for the scope is a hot path
     * rather than an error path.
     */
    private ?string $extensionRoot;

    /**
     * @param string $extensionName The manifest name, used to build the scoped
     *                              router this proxy hands back in place of a
     *                              real one
     * @param list<ExtensionCapability> $additionalCapabilities Per-extension extra grants
     * @param ScopeRegistrations $registrations Ids this extension bound through
     *                                          this scope; see the class for why
     *                                          resolving one is not a grant
     * @param string $extensionPath The extension's own directory, from its
     *                              manifest. Decides which classes count as
     *                              code this extension SHIPS — see
     *                              {@see self::provideThroughScope()} — and
     *                              which configuration sections it owns, see
     *                              {@see self::sectionsShippedIn()}.
     * @param ExtensionSurfaces $surfaces What the OTHER loaded extensions
     *                                    publish; see the class for why a peer's
     *                                    declared service is neither host graph
     *                                    nor own code
     */
    public function __construct(
        private ContainerInterface $inner,
        private TrustTier $tier,
        private CapabilityPolicy $policy,
        private ServiceRestrictionMap $restrictionMap,
        private string $extensionName = '',
        private array $additionalCapabilities = [],
        private ScopeRegistrations $registrations = new ScopeRegistrations(),
        string $extensionPath = '',
        private ExtensionSurfaces $surfaces = new ExtensionSurfaces(),
    ) {
        $this->ownConfigSections = self::sectionsShippedIn($extensionPath);
        $this->extensionRoot = self::resolvedRoot($extensionPath);
    }

    /**
     * The extension's directory as an absolute, slash-normalised path, or null
     * when there is none to compare against.
     */
    private static function resolvedRoot(string $extensionPath): ?string
    {
        if ($extensionPath === '') {
            return null;
        }

        $root = realpath($extensionPath);

        return $root === false ? null : rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * Whether THIS SCOPE can return an entry for the id.
     *
     * It used to delegate to the inner container and was documented as never
     * lying about existence. It was the lie: PSR-11 defines `has()` as "returns
     * true if the container can return an entry for the given identifier", and
     * this container cannot return `MasterKey` to a Verified extension — it
     * throws. Reporting the host's binding table instead turned the ONE idiom
     * PSR-11 exists to support into a trap:
     *
     * ```php
     * if ($container->has(MasterKey::class)) {      // true, from the host
     *     $key = $container->get(MasterKey::class); // CapabilityDeniedException
     * }
     * ```
     *
     * That is not a hypothetical. It is `pulsar/cms`'s
     * `CmsCoreServiceProvider`, and it is why the CMS could not register at the
     * `verified` tier this framework ships it at. Every bundled extension uses
     * the same guard to degrade gracefully around an optional service, and the
     * proxy answered every one of them with "yes" and then threw. An extension
     * cannot write defensive code against a predicate that is wrong.
     *
     * Answering for the scope also stops `has()` being a free map of the host's
     * restricted bindings: an Untrusted extension could previously enumerate
     * which crypto, database and audit services a deployment had wired without
     * resolving any of them.
     *
     * The inner container's answer is still required — this narrows the host's
     * table, it never widens it — and the internal machinery that must ask about
     * the real table ({@see self::resolve()},
     * {@see self::provideThroughScope()}, {@see self::assertCanRegister()})
     * calls `$this->inner->has()` and the binding lists directly, so none of
     * them reads this narrowed view by accident.
     */
    #[Override]
    public function has(string $id): bool
    {
        return $this->inner->has($id) && $this->canResolve($id);
    }

    /**
     * Resolve a service, enforcing capability checks and containing the result.
     *
     * Carries {@see ContainerInterface::get()}'s conditional return type rather
     * than flattening it to `mixed`: an extension resolving a class-string gets
     * the same inference through the proxy as through the container it stands
     * in for, and callers here keep a type to reason about.
     *
     * The containment used to be skipped for ids the restriction map named, on
     * the reasoning that paying a capability must buy the service. That is
     * true, and it is now expressed inside {@see self::contain()} — which lets a
     * priced root through to a holder of its capability — rather than by
     * bypassing the guard for half the ids. A guard with an exception branch is
     * a guard someone will route around, and this one was.
     *
     * @template T of object
     * @param string|class-string<T> $id
     * @return ($id is class-string<T> ? T : mixed)
     *
     * @throws CapabilityDeniedException If the extension's tier lacks the required capability
     * @throws NotFoundException If the id resolves to no binding — the inner container's
     *                           own answer, passed through unchanged so a caller cannot
     *                           tell a missing service from a denied one by exception type
     *                           alone (`has()` remains the truthful way to ask)
     * @throws ContainerException If the id names a class this scope builds itself
     *                            ({@see self::resolve()}) and building it fails
     */
    #[Override]
    #[NoDiscard]
    public function get(string $id): mixed
    {
        $this->assertCanResolve($id);

        /** @var mixed $value */
        $value = $this->resolve($id);

        return $this->contain($value, sprintf('service "%s"', $id));
    }

    /**
     * Where the value comes from, once the id is allowed.
     *
     * An UNBOUND class the extension ships is built here rather than delegated,
     * and the difference is not a nicety. `Container::get()` autowires an
     * unbound instantiable concrete from the real graph, so delegating would
     * take an id this scope had just approved and fill its constructor with
     * services the scope never saw — `Own(Collaborator)` where
     * `Collaborator(MasterKey)` walks straight out. The permission to resolve
     * the extension's own class is not permission for the container to decide
     * what goes inside it.
     *
     * Everything with an actual binding delegates, because a binding is
     * something someone declared: the host's, or this scope's own
     * ({@see self::scopedConcrete()} already rebound that one).
     *
     * @throws CapabilityDeniedException
     * @throws ContainerException
     * @throws NotFoundException
     */
    private function resolve(string $id): mixed
    {
        if (
            !$this->inner->has($id)
            && $this->isOwnCode($id)
            && class_exists($id)
            && new ReflectionClass($id)->isInstantiable()
        ) {
            return $this->construct($id);
        }

        return $this->inner->get($id);
    }

    /**
     * @param callable|class-string $concrete
     */
    #[Override]
    public function bind(string $id, callable|string $concrete, BindingType $type = BindingType::Singleton): void
    {
        $this->assertCanRegister($id);
        $this->inner->bind($id, $this->scopedConcrete($concrete), $type);
        $this->registrations->record($id);
    }

    /**
     * @param callable|class-string $concrete
     */
    #[Override]
    public function singleton(string $id, callable|string $concrete): void
    {
        $this->assertCanRegister($id);
        $this->inner->singleton($id, $this->scopedConcrete($concrete));
        $this->registrations->record($id);
    }

    #[Override]
    public function instance(string $id, object $instance): void
    {
        $this->assertCanRegister($id);
        $this->inner->instance($id, $instance);
        $this->registrations->record($id);
    }

    /**
     * Wrap an existing service, receiving the current one.
     *
     * Two things cross the boundary here and both were unguarded. The DECORATOR
     * is extension code the container invokes as `($inner, $container)` with the
     * real container — closed by binding the second argument to this proxy. And
     * `$inner` is THE SERVICE ITSELF, handed to extension code as the first
     * argument, which is a resolution by another name: `decorate()` never
     * consulted the id, so a tier that could not `get('...\MasterKey')` could
     * decorate it and be given the key. The id is now asserted exactly as a
     * `get()` would assert it, and the service is contained on the way in.
     *
     * A decorator CLASS is constructed by the container as `new $class($inner)`
     * — one argument, no autowiring — so it goes through the same closure
     * instead of straight to the container, for the sake of that one argument.
     */
    #[Override]
    public function decorate(string $id, string|callable $decorator, int $priority = 0): void
    {
        $this->assertCanDecorate();
        $this->assertCanResolve($id);

        $origin = sprintf('the service decorated at "%s"', $id);

        if (!is_string($decorator)) {
            $closure = $decorator;
            $this->inner->decorate(
                $id,
                fn(object $service): mixed => $closure($this->contain($service, $origin), $this),
                $priority,
            );

            return;
        }

        $decoratorClass = $decorator;
        $this->inner->decorate(
            $id,
            fn(object $service): object => $this->construct(
                $decoratorClass,
                [$this->contain($service, $origin)],
            ),
            $priority,
        );
    }

    #[Override]
    public function forgetInstance(string $id): void
    {
        $this->assertCanWrite();
        $this->inner->forgetInstance($id);
    }

    #[Override]
    public function setResolutionHints(?array $hints): void
    {
        $this->assertCanWrite();
        $this->inner->setResolutionHints($hints);
    }

    #[Override]
    #[NoDiscard]
    public function getBindings(): array
    {
        $this->assertCanRead();

        return $this->inner->getBindings();
    }

    #[Override]
    #[NoDiscard]
    public function getInstances(): array
    {
        $this->assertCanRead();

        return $this->inner->getInstances();
    }

    /**
     * Invoke a callable, resolving its parameters THROUGH THIS PROXY and
     * containing what it returns.
     *
     * {@see \Pulsar\Container\Container::call()} autowires by calling its own
     * `get()`, so delegating to it turned this into the widest hole in the
     * sandbox: `call(fn(ContainerInterface $c) => $c)` returned the real
     * container and `call(fn(MasterKey $k) => $k)` returned the master key,
     * neither consulting the restriction map, the capability policy or the tier.
     *
     * The first repair pre-resolved the parameters here and passed them to the
     * inner `call()` as explicit arguments — which left the inner container in
     * the loop for anything the pre-pass declined to supply, and the two
     * disagreed about what "supplied" means: the pre-pass skipped a parameter
     * present in `$params` using `array_key_exists()`, and the inner `call()`
     * decided a parameter was NOT supplied using `isset()`. A single null slot
     * split them apart, and `call(fn(MasterKey $k) => $k, ['k' => null])`
     * autowired the master key from the real graph.
     *
     * So the inner container is not in the loop at all. The arguments are built
     * here, in the container's own order of preference — explicit parameter,
     * then resolution through {@see self::get()}, then the declared default,
     * then null for a nullable — and the callable is invoked directly. A denial
     * propagates: a callable may not receive by injection what the extension
     * could not have resolved by name.
     *
     * @param array<string, mixed> $params
     *
     * @throws CapabilityDeniedException If a parameter names a service this tier cannot resolve
     * @throws ContainerException If a required parameter cannot be resolved
     * @throws ReflectionException If the callable cannot be reflected
     */
    #[Override]
    public function call(callable $callable, array $params = []): mixed
    {
        $arguments = array_map(
            fn(ReflectionParameter $parameter): mixed => $this->argumentFor($parameter, $params, 'callable'),
            CallableReflector::reflect($callable)->getParameters(),
        );

        /** @var mixed $result */
        $result = $callable(...$arguments);

        return $this->contain($result, 'the return value of a scoped call()');
    }

    /**
     * Build a class the extension named, through this scope.
     *
     * This is the inversion. The framework constructs extension-supplied classes
     * in five places — a binding by class name, a service provider, a route
     * handler, a route middleware, a CLI command — and every one of them used
     * the REAL container, which fills a constructor from the real graph. The
     * previous defence was to predict what the container would put in there
     * ({@see SandboxReachAnalyzer}, four hops, `class_exists()` guard); it was
     * escaped by adding a hop, and by binding a class before defining it.
     *
     * Nothing is predicted now. Each constructor parameter is resolved through
     * {@see self::get()}, so the tier, the restriction map and the value guard
     * apply to it, and to whatever it in turn depends on, and so on, with no
     * depth bound. A class that does not exist yet cannot be built either, so
     * "define it later" reaches this method rather than slipping past it.
     *
     * A denial is re-reported against the class rather than the parameter,
     * because "provider X asks for MasterKey" is actionable and "MasterKey is
     * denied" is not.
     *
     * @param list<mixed> $leading Arguments the caller supplies positionally
     *                             before the resolved ones — the decorated
     *                             service for a decorator class, and nothing
     *                             else so far
     *
     * @throws CapabilityDeniedException If a constructor parameter names something this tier may not hold
     * @throws ContainerException If the class cannot be built
     */
    #[NoDiscard]
    public function construct(string $className, array $leading = []): object
    {
        if (!class_exists($className)) {
            throw ContainerException::unresolvable(
                $className,
                'Class does not exist, so the extension sandbox cannot build it through the scope',
            );
        }

        /** @var class-string $className */
        $reflection = new ReflectionClass($className);

        if (!$reflection->isInstantiable()) {
            throw ContainerException::unresolvable($className, 'Class is not instantiable');
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        // Parameters the caller already filled are skipped by position: a
        // decorator class takes the decorated service first and its own
        // dependencies after it.
        $resolved = array_map(
            fn(ReflectionParameter $parameter): mixed => $this->argumentFor($parameter, [], $className),
            array_slice($constructor->getParameters(), count($leading)),
        );

        return $reflection->newInstanceArgs([...$leading, ...$resolved]);
    }

    /**
     * Register a class the extension named so that resolving it builds it
     * through this scope.
     *
     * The framework constructs a route handler, a route middleware and a CLI
     * command from a class NAME, long after the extension that named it has
     * finished booting, using the container it has to hand — the real one. There
     * is no scope in scope at that moment, and nothing about the class name says
     * which extension it belongs to.
     *
     * Binding a factory now is what carries the scope across that gap, the same
     * way {@see ScopedDeferredProvider} carries it across a deferred
     * `register()`. The container resolves the id it was going to resolve
     * anyway; the factory behind it is this scope's.
     *
     * Never an override: a class the HOST has already bound keeps the host's
     * binding, so routing at a class the host owns cannot take it over — the
     * `has()` check returns before anything is written.
     *
     * Nor is it ever a class the host merely has not bound YET. `analytics`
     * attaches the FRAMEWORK's `Pulsar\Security\Csrf\CsrfMiddleware` to its own
     * routes, which nothing had bound; claiming it would have put a framework
     * class's construction permanently inside one extension's tier, so the
     * framework's own use of it would answer to that extension's capabilities
     * and could be made to fail by it. Only code the extension SHIPS is claimed.
     *
     * "Ships" is decided by where the class's file is, not by what it is called.
     * A prefix rule would be a name rule, and an extension can register its own
     * autoloader in `register()` and serve any name it likes from anywhere; a
     * file path is where the code physically is. Composer owns the framework's
     * `src/`, so a framework class resolves to a file outside every extension
     * directory, and the only way for a class to resolve INSIDE one is for the
     * extension to have shipped it — at which point it is the extension's class
     * and building it here is exactly right.
     *
     * An extension with no manifest path — added programmatically, as tests do —
     * claims nothing, because there is no directory to compare against. That is
     * the one case where this mechanism has nothing to work with, and it is
     * pinned by a test rather than left to be discovered.
     *
     * Past those checks this IS a registration and is charged as one. A route
     * handler is a service the extension is putting into the host's container
     * under a name of its choosing, and calling it anything else would leave a
     * public method on the scope that writes to the container for free.
     *
     * @throws CapabilityDeniedException If the tier may not register a service
     */
    public function provideThroughScope(string $className): void
    {
        if (!class_exists($className) || $this->inner->has($className) || !$this->isOwnCode($className)) {
            return;
        }

        $this->assertCanRegister($className);
        $this->inner->singleton($className, fn(): object => $this->construct($className));
        $this->registrations->record($className);
    }

    /**
     * Whether the type is declared in a file inside this extension's own
     * directory — that is, whether the extension SHIPS it.
     *
     * The one question the sandbox has to answer about identity, and the reason
     * it is answered by file path rather than by namespace: a namespace is a
     * name, an extension can register its own autoloader in `register()`, and
     * every name-shaped rule in this sandbox's history has been walked around
     * by supplying a different name. Where a file physically is, is not
     * something an extension can restate. Composer owns the framework's `src/`,
     * so a framework type resolves outside every extension directory; a type
     * that resolves INSIDE one got there by being shipped there.
     *
     * Used for two things, and both are about construction rather than
     * permission: which classes {@see self::provideThroughScope()} may claim,
     * and which ids {@see self::assertCanResolve()} lets through after the
     * restriction map has had its say. An extension resolving its own
     * `Contracts\TicketRepositoryInterface`, or autowiring its own
     * `Dsar\AnalyticsDsarCollector`, is doing the thing extensions exist to do;
     * deny-by-default is about the HOST's service graph, and an extension's own
     * class was never part of it.
     */
    /**
     * The section names an extension's own `config/*.php` files publish.
     *
     * Mirrors the rule the boot-time config publisher applies: a section is
     * named after its config file's basename with `-` normalised to `_`, so
     * `config/ai-governance.php` publishes as `ai_governance`. The publisher is
     * described rather than named, because naming a type from the Kernel's boot
     * machinery here would be the module dependency the architecture rules
     * forbid — and they scan class-name STRINGS, not only imports. Derived from
     * where the FILES are for the same reason {@see self::isOwnCode()} is
     * derived from where the classes are: a section name is a name, and an
     * extension can ask for any name it likes; a file in its own directory is
     * something it actually shipped.
     *
     * An extension with no manifest path — added programmatically, as tests do —
     * owns nothing, which is the same answer {@see self::isOwnCode()} gives and
     * for the same reason: there is no directory to compare against.
     *
     * @return list<string>
     */
    private static function sectionsShippedIn(string $extensionPath): array
    {
        if ($extensionPath === '') {
            return [];
        }

        $files = glob($extensionPath . '/config/*.php');

        if ($files === false) {
            return [];
        }

        return array_map(
            static fn(string $file): string => str_replace('-', '_', basename($file, '.php')),
            $files,
        );
    }

    private function isOwnCode(string $type): bool
    {
        // The root is resolved once in the constructor rather than here: this
        // method is on the path of every `has()` and every `get()` that reaches
        // deny-by-default, and `realpath()` is a filesystem call against a
        // directory that cannot move while the process runs.
        if ($this->extensionRoot === null || (!class_exists($type) && !interface_exists($type))) {
            return false;
        }

        $declaredIn = new ReflectionClass($type)->getFileName();

        if ($declaredIn === false) {
            return false;
        }

        $file = realpath($declaredIn);

        if ($file === false) {
            return false;
        }

        return str_starts_with(str_replace('\\', '/', $file), $this->extensionRoot . '/');
    }

    /**
     * The extension's own scoped router.
     *
     * Built here rather than by the bootstrap so that the two ways an extension
     * can come by a router — the argument to `boot()`, and a container
     * resolution that happens to return one — produce the same object with the
     * same scope. They did not, before: {@see self::contain()} built one and the
     * bootstrap built another, and only one of them knew about this scope.
     *
     * Declared as the contract, not as the proxy it builds. Both callers — the
     * exchange in {@see self::contain()} and {@see \Pulsar\Extensibility\ExtensionBootstrap}
     * — hand the result straight on as a router, so the concrete return type only
     * pinned them to a `final` class nothing outside this namespace can wrap.
     */
    #[NoDiscard]
    public function scopedRouter(RouterInterface $router): RouterInterface
    {
        return new ScopedRouterProxy($router, $this->tier, $this->extensionName, $this->policy, $this);
    }

    /**
     * One argument, resolved the way the container would resolve it, through
     * this scope.
     *
     * @param array<string, mixed> $explicit
     * @param string $subject What is being built, for the denial message
     *
     * @throws CapabilityDeniedException
     * @throws ContainerException
     */
    private function argumentFor(ReflectionParameter $parameter, array $explicit, string $subject): mixed
    {
        $name = $parameter->getName();

        // array_key_exists, not isset: a caller that explicitly passes null has
        // supplied the argument. Reading that as "absent" is what let the old
        // call() hand a denied service to a parameter the caller had already
        // filled.
        if (array_key_exists($name, $explicit)) {
            return $explicit[$name];
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $type->getName();

            try {
                return $this->get($dependency);
            } catch (NotFoundException | ContainerException) {
                // Unresolvable: fall through to the default, exactly as the
                // container does. A CapabilityDeniedException is deliberately
                // NOT caught here — a denial must not silently become a null.
            } catch (CapabilityDeniedException $denial) {
                throw $this->denialFor($subject, $dependency, $denial);
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        throw ContainerException::unresolvable(
            $subject,
            sprintf(
                'Cannot resolve parameter "%s": no binding this extension may resolve, and no default value',
                $name,
            ),
        );
    }

    /**
     * Re-report a dependency denial against the thing being built.
     *
     * @throws CapabilityDeniedException
     */
    private function denialFor(
        string $subject,
        string $dependency,
        CapabilityDeniedException $denial,
    ): CapabilityDeniedException {
        $required = $this->restrictionMap->requiredCapability($dependency);

        if ($required !== null) {
            return CapabilityDeniedException::forInjectedService($subject, $dependency, $this->tier, $required);
        }

        $root = SandboxReach::rootFor($dependency);

        if ($root !== null) {
            return CapabilityDeniedException::forAutowiredReach(
                $subject,
                sprintf('%s [%s]', $dependency, $root),
                $this->tier,
            );
        }

        return $denial;
    }

    /**
     * Wrap what the container registers so extension code is never handed the
     * real container while building it, and never builds outside the scope.
     *
     * A factory CLOSURE is invoked by the container as `$factory($container)`
     * with the real container, so it is re-bound to this proxy. A class NAME
     * used to be vetted and then left to the container to autowire; it is now
     * turned into a factory that builds it here ({@see self::construct()}),
     * which is the whole difference between predicting the constructor and
     * filling it.
     *
     * @param callable|class-string $concrete
     * @return callable
     */
    private function scopedConcrete(callable|string $concrete): callable
    {
        if (is_string($concrete)) {
            $className = $concrete;

            return fn(): object => $this->construct($className);
        }

        $factory = $concrete;

        return fn(): mixed => $factory($this);
    }

    /**
     * The last thing between a value and the extension holding it, on every
     * path out of the scope.
     *
     * {@see self::assertCanResolve()} judges the ID that was asked for; this
     * judges what came back, because an ID is a name the composition root chose
     * and says nothing about the object behind it. A container or a router is
     * exchanged for this extension's scoped one — same contract, same tier, no
     * new reach.
     *
     * Anything else that dispenses services is refused UNLESS the restriction
     * map prices it and the extension holds the price. That clause is what makes
     * this callable on every exit instead of on some of them: `ConfigRepository`
     * and the middleware pipeline are reach-capable AND are what `ConfigWrite`
     * and `MiddlewareRegister` are for, so a guard with no notion of payment had
     * to be skipped for restricted ids — and `get()` skipped it, on exactly the
     * branch where the extension had asked for something sensitive.
     *
     * Read against a CORRECT configuration the refusal looks unreachable, and
     * that is the point rather than an argument for deleting it. Deny-by-default
     * already refuses every unclassified ID, and `SandboxReachAnalyzerTest`
     * proves no safe-listed TYPE leads to a dispenser — but neither fact
     * constrains what an application BINDS to a safe-listed ID. The original
     * defect was exactly that gap: `ContainerInterface` was classified safe and
     * the Kernel bound it to the real container. A host that binds
     * `Psr\Log\LoggerInterface` to something that also implements PSR-11
     * re-creates it, and nothing static can see that coming. This is where it
     * stops.
     *
     * @throws CapabilityDeniedException
     */
    private function contain(mixed $value, string $origin): mixed
    {
        if (!is_object($value)) {
            return $value;
        }

        // Deliberately not short-circuited on "it is already a proxy": a proxy
        // that arrived from the container belongs to whichever scope built it,
        // which is not necessarily this one. Exchanging it for this extension's
        // own scope is the only answer that is right in both cases.
        if (SandboxReach::isContainer($value)) {
            return $this;
        }

        if (SandboxReach::isRouter($value)) {
            /** @var RouterInterface $value */
            return $this->scopedRouter($value);
        }

        // The third exchange, and the one that is not about reach. A registry
        // holds every extension's configuration in one object, and a real
        // deployment's `payments` section carries a webhook secret and a
        // provider API key — so the whole object is a credential store for
        // extensions that have nothing to do with payments. Narrowed to the
        // sections this extension ships, it hands back exactly what the
        // extension put there (or what the operator overrode it with) and
        // nothing belonging to anyone else.
        if ($value instanceof ExtensionConfigRegistry) {
            return $value->restrictedTo($this->ownConfigSections);
        }

        $root = SandboxReach::refusedRootFor($value);

        if ($root === null) {
            return $value;
        }

        $price = $this->restrictionMap->requiredCapability($root);

        if ($price !== null && $this->hasCapability($price)) {
            return $value;
        }

        throw CapabilityDeniedException::forReachableValue($origin, $root, $this->tier);
    }

    /**
     * The same question {@see self::assertCanResolve()} asks, without throwing.
     *
     * Split out so {@see self::has()} and `get()` cannot drift apart. They had:
     * `has()` reported the host's table and `get()` applied the tier, so every
     * `if ($c->has($x)) { $c->get($x); }` in the bundled extensions was a
     * guarded call that the guard did not protect. One predicate, two callers,
     * and the only difference between them is whether the "no" is a `false` or
     * a message naming the capability that would have bought it.
     */
    #[NoDiscard]
    private function canResolve(string $serviceId): bool
    {
        // Checked before the map, and unconditionally: see the constant's
        // docblock. A tier that can resolve the container has no tier.
        if (in_array($serviceId, self::SANDBOX_DEFEATING_SERVICES, true)) {
            return false;
        }

        // Check restricted services first
        if ($this->restrictionMap->isRestricted($serviceId)) {
            $required = $this->restrictionMap->requiredCapability($serviceId);

            return $required === null || $this->hasCapability($required);
        }

        // Safe services are always allowed with ContainerRead
        if ($this->restrictionMap->isSafe($serviceId)) {
            return true;
        }

        // An id this extension registered through this scope. Checked AFTER the
        // two lists above, so it can only rescue something nothing else
        // classified — see ScopeRegistrations for why that is a fact about
        // construction rather than a permission.
        if ($this->registrations->owns($serviceId)) {
            return true;
        }

        // A type the extension SHIPS. Deny-by-default is a rule about the
        // HOST's service graph, and an extension's own class has never been
        // part of it: `TicketsConfig`, `Contracts\StatsServiceInterface`,
        // `Dsar\AnalyticsDsarCollector` are the extension's own vocabulary, and
        // refusing them refuses the extension the use of itself. Checked after
        // the restriction map, so shipping a class under a restricted id's name
        // buys nothing. See self::isOwnCode() for why this is a file path and
        // not a namespace prefix.
        if ($this->isOwnCode($serviceId)) {
            return true;
        }

        // A type ANOTHER loaded extension publishes. Neither host graph nor own
        // code, and refusing it made every documented extension-to-extension
        // integration fail: `pulsar/forum` registering its resources with
        // `pulsar/admin`, its account pages with `pulsar/cms`. The decision is
        // the PROVIDER's, taken in its own manifest and anchored to the file the
        // type is declared in — see ExtensionSurfaces for why the consumer is
        // not asked and why a name alone proves nothing.
        if ($this->surfaces->isPublished($serviceId)) {
            return true;
        }

        // Unknown service; deny by default for non-Core tiers
        return $this->tier->atLeast(TrustTier::Core);
    }

    /**
     * @throws CapabilityDeniedException
     */
    private function assertCanResolve(string $serviceId): void
    {
        if ($this->canResolve($serviceId)) {
            return;
        }

        // Past here the answer is no, and the rest of this method exists only to
        // say WHY in the terms the reader can act on: which door was closed, and
        // what would open it.
        if (in_array($serviceId, self::SANDBOX_DEFEATING_SERVICES, true)) {
            throw CapabilityDeniedException::forSandboxEscape($serviceId, $this->tier);
        }

        $required = $this->restrictionMap->requiredCapability($serviceId);

        if ($required !== null) {
            throw CapabilityDeniedException::forService($serviceId, $this->tier, $required);
        }

        throw CapabilityDeniedException::forUnknownService($serviceId, $this->tier);
    }

    private function assertCanRead(): void
    {
        if (!$this->hasCapability(ExtensionCapability::ContainerRead)) {
            throw CapabilityDeniedException::forCapability($this->tier, ExtensionCapability::ContainerRead);
        }
    }

    private function assertCanWrite(): void
    {
        if (!$this->hasCapability(ExtensionCapability::ContainerWrite)) {
            throw CapabilityDeniedException::forCapability($this->tier, ExtensionCapability::ContainerWrite);
        }
    }

    /**
     * Registering a service the extension provides vs overriding an existing one.
     *
     * Rebinding an id that is ALREADY explicitly bound can hijack a core service
     * (the rc.12 Session/Auth/CsrfGuard override hole), so that override power is
     * ContainerWrite — Core only. Binding a NEW id — the extension's own service,
     * or filling an unbound extension point — is the lesser ServiceRegister power
     * available to Verified and Community. `has()` (PSR-11) is deliberately NOT
     * used here: it is true for any autowirable class, which would misclassify a
     * first-time registration as an override; only an EXPLICIT binding or cached
     * instance counts as "already registered".
     */
    private function assertCanRegister(string $id): void
    {
        $alreadyRegistered = in_array($id, $this->inner->getBindings(), true)
            || in_array($id, $this->inner->getInstances(), true);

        if ($alreadyRegistered) {
            $this->assertCanWrite();

            return;
        }

        if (
            $this->hasCapability(ExtensionCapability::ServiceRegister)
            || $this->hasCapability(ExtensionCapability::ContainerWrite)
        ) {
            return;
        }

        throw CapabilityDeniedException::forCapability($this->tier, ExtensionCapability::ServiceRegister);
    }

    /**
     * Decorating an existing service (wrapping it, original preserved) is the
     * ServiceDecorate power — Verified and above. It is strictly less than
     * ContainerWrite (override/replace), which also satisfies it. Community is
     * denied: a decorator can still subvert behaviour of a core service.
     */
    private function assertCanDecorate(): void
    {
        if (
            $this->hasCapability(ExtensionCapability::ServiceDecorate)
            || $this->hasCapability(ExtensionCapability::ContainerWrite)
        ) {
            return;
        }

        throw CapabilityDeniedException::forCapability($this->tier, ExtensionCapability::ServiceDecorate);
    }

    private function hasCapability(ExtensionCapability $capability): bool
    {
        if ($this->policy->allows($this->tier, $capability)) {
            return true;
        }

        return in_array($capability, $this->additionalCapabilities, true);
    }
}
