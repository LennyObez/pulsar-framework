<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extensibility;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\TrustedExtensionsConfig;
use Pulsar\Container\Container;
use Pulsar\Core\Boot\ExtensionSandbox;
use Pulsar\Core\Kernel;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Exception\CapabilityDeniedException;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Extensibility\ExtensionLifecycle;
use Pulsar\Extensibility\ExtensionLoader;
use Pulsar\Extensibility\ExtensionManifest;
use Pulsar\Extensibility\ExtensionRegistry;
use Pulsar\Extensibility\Internal\ScopedContainerProxy;
use Pulsar\Extensibility\Internal\ScopedRouterProxy;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Stringable;
use Throwable;

use function array_keys;
use function array_map;
use function bin2hex;
use function class_exists;
use function dirname;
use function file_get_contents;
use function getenv;
use function implode;
use function in_array;
use function interface_exists;
use function is_array;
use function is_string;
use function ob_end_clean;
use function ob_start;
use function preg_match;
use function putenv;
use function random_bytes;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * Boots this framework with its own bundled extensions, at the tiers
 * `config/extensions.php` grants them, and holds each one to what its manifest
 * says it provides.
 *
 * ## Why this test exists
 *
 * Two consecutive rounds of sandbox hardening shipped having broken the
 * extensions the framework ships. The first denied every extension above Core
 * the resolution of its own bindings, so nothing bundled booted. The second
 * closed that and left `pulsar/cms` unable to register the catch-all a CMS is
 * built around, `pulsar/booking`, `pulsar/payments` and `pulsar/analytics`
 * unable to read the configuration they ship or register an import/export
 * provider, and `pulsar/forum` unable to reach either of the extensions it
 * integrates with. Every one of those was reachable by running the framework
 * for two seconds, and no test ran it.
 *
 * The unit tests could not have caught any of them, and not by accident: a unit
 * test of the sandbox supplies its own fixture extension, so it asserts the
 * proxy does what the proxy was written to do. What was wrong was never the
 * proxy in isolation — it was the proxy's idea of what a real extension needs,
 * and the only witness to that is a real extension.
 *
 * ## What it asserts, and why each one
 *
 *  - Every bundled extension reaches {@see ExtensionLifecycle::Booted}. A
 *    capability denial anywhere in the five phases fails the extension and
 *    leaves the state behind, so this alone catches every denial in
 *    `register`, `preBoot`, `boot`, `postBoot` and `shutdown`.
 *  - Every service its manifest promises that the framework delivers WITHOUT
 *    the sandbox is still delivered WITH it, and every route it registers
 *    without the sandbox is still registered with it. Reaching `Booted` proves
 *    nothing threw at the end of a phase; this proves the work inside the phase
 *    happened. `pulsar/cms` reached `Booted` while a denial part-way through
 *    `boot()` cost it 166 of its 211 routes.
 *  - Nothing an extension registered is refused TO IT at resolution time — every
 *    route-handler class and every id it bound. This is the one that reaches
 *    past boot: containment by construction builds route handlers, route
 *    middleware and CLI commands inside the extension's scope, so a
 *    controller's constructor is checked when the first request arrives, and a
 *    lazily-bound factory closure when the id is first asked for. Five bundled
 *    controllers and one payments binding were denied while every boot-time
 *    assertion here was green.
 *  - No class in `provides.commands` is refused by its own extension's tier
 *    when built through {@see ExtensionBootstrap::buildCommand()}, the
 *    CommandRegister enforcement site and the path `bin/pulsar` takes.
 *  - `shutdown()` completes for all of them.
 *
 * ## Why the comparison, rather than the manifest, is the yardstick
 *
 * "Everything in `provides.services` is registered" was the first version of
 * this and it is the wrong assertion. Forty-three entries across ten bundled
 * extensions do not register in a bare boot — most bind only once a database
 * connection or a feature flag is present, nine name a type the extension no
 * longer ships — and the identical forty-three are missing with the sandbox
 * off. A sandbox test failing on that would report someone else's defect and
 * say nothing about this one.
 *
 * Comparing against the same framework, in the same process, with every
 * extension at Core instead — the sandbox off by the framework's own definition
 * — asks only the question this file is for: did the sandbox take anything.
 * The reference is computed on every run, never written down, so it cannot
 * decay into a stale allowance that grows.
 */
#[CoversClass(ExtensionBootstrap::class)]
#[CoversClass(ExtensionSandbox::class)]
#[CoversClass(ScopedContainerProxy::class)]
#[CoversClass(ScopedRouterProxy::class)]
final class BundledExtensionContractTest extends TestCase
{
    /**
     * The bundled products, which are off by default so a regulated application
     * does not inherit a forum it never asked for. Every one of them is turned
     * ON here: they are the extensions the sandbox actually proxies — the
     * infrastructure extensions run at Core and bypass it — so a test that left
     * them off would exercise the tier that has nothing to prove.
     *
     * Kept complete by {@see self::everyBundledProductIsExercised()} rather than
     * by hand.
     *
     * @var list<string>
     */
    private const array PRODUCTS = [
        'pulsar/ai-governance',
        'pulsar/analytics',
        'pulsar/booking',
        'pulsar/cms',
        'pulsar/devices',
        'pulsar/feedback',
        'pulsar/forum',
        'pulsar/health-status',
        'pulsar/messaging',
        'pulsar/payments',
        'pulsar/releases',
        'pulsar/subscriptions',
        'pulsar/tickets',
    ];

    private string $repositoryRoot;
    private string|false $masterKeyBefore;

    private ExtensionBootstrap $bootstrap;
    private Container $container;
    private Router $router;

    /** @var AbstractLogger&object{messages: list<string>} */
    private AbstractLogger $errors;

    /** @var array{bootstrap: ExtensionBootstrap, container: Container, router: Router}|null */
    private ?array $unsandboxed = null;

    protected function setUp(): void
    {
        $this->repositoryRoot = dirname(__DIR__, 3);

        // `pulsar/cms` derives its preview-link, API-key-pepper and fingerprint
        // subkeys from the master key and refuses to finish registering without
        // one — see the CryptoKeyAccess grant in config/extensions.php. A key
        // is supplied here so the test measures the sandbox and not the
        // environment; without it the CMS fails identically with the sandbox
        // switched off, which would make a green run meaningless.
        $this->masterKeyBefore = getenv('PULSAR_MASTER_KEY');
        putenv('PULSAR_MASTER_KEY=' . bin2hex(random_bytes(32)));

        $this->boot();
    }

    protected function tearDown(): void
    {
        putenv(
            $this->masterKeyBefore === false
                ? 'PULSAR_MASTER_KEY'
                : 'PULSAR_MASTER_KEY=' . $this->masterKeyBefore,
        );
    }

    #[Test]
    public function everyBundledExtensionBootsAtTheTierTheHostGrantsIt(): void
    {
        $failed = [];

        foreach (array_keys($this->bootstrap->registry->all()) as $name) {
            $state = $this->bootstrap->registry->getState($name);

            if ($state !== ExtensionLifecycle::Booted) {
                $failed[] = sprintf('%s (%s)', $name, $state->name);
            }
        }

        self::assertSame(
            [],
            $failed,
            'Bundled extensions that did not reach Booted: ' . implode(', ', $failed),
        );
    }

    /**
     * Every service a manifest promises, that the framework delivers with the
     * sandbox switched OFF, is still delivered with it ON.
     *
     * The obvious assertion — "everything in `provides.services` is registered"
     * — was written first and is the wrong one, for a reason worth recording
     * rather than quietly working around. Forty-three entries across ten bundled
     * extensions are not registered in a bare boot, and the identical
     * forty-three are missing whether the sandbox is engaged or not: most name a
     * service that only binds once a database connection or a feature flag is
     * present, and nine name a type their extension no longer ships at all. That
     * is manifest drift, it predates every round of sandbox work, and a sandbox
     * test that failed on it would be reporting someone else's defect while
     * saying nothing about this one.
     *
     * So the comparison is against the framework running WITHOUT the sandbox,
     * in the same process, with the same configuration and the same products
     * enabled. Anything the extension delivers there and not here, the sandbox
     * took — which is the entire question this file exists to answer, and the
     * one no amount of manifest drift can blur. The reference is computed, never
     * written down, so it cannot become a stale allowance that quietly grows.
     */
    #[Test]
    public function theSandboxCostsNoExtensionAServiceItsManifestPromises(): void
    {
        $unsandboxed = $this->bootUnsandboxed();
        $lost = [];

        foreach ($this->bootstrap->getManifests() as $manifest) {
            $root = $this->rootOf($manifest);

            if ($root === null) {
                continue;
            }

            foreach ($manifest->provides->services as $service) {
                $type = $this->typeShippedAs($service, $root);

                if ($type === null || !$unsandboxed['container']->has($type)) {
                    continue;
                }

                if (!$this->container->has($type)) {
                    $lost[] = sprintf('%s => %s (%s)', $manifest->name, $service, $type);
                }
            }
        }

        self::assertSame(
            [],
            $lost,
            "Services the sandbox cost their extension:\n  " . implode("\n  ", $lost),
        );
    }

    /**
     * The same comparison for routes, and the one that would have caught the CMS
     * on its own: reaching `Booted` says nothing about how far through `boot()`
     * an extension got, and a denial part-way through leaves the extension
     * looking healthy and its route table short.
     *
     * Counted per extension by the file each route's HANDLER is declared in —
     * the same physical attribution the sandbox itself uses to decide whose code
     * is whose, and the only one an extension cannot restate.
     */
    #[Test]
    public function theSandboxCostsNoExtensionARoute(): void
    {
        $unsandboxed = $this->bootUnsandboxed();
        $short = [];

        foreach ($this->bootstrap->getManifests() as $manifest) {
            $root = $this->rootOf($manifest);

            if ($root === null) {
                continue;
            }

            $expected = $this->routesHandledBy($unsandboxed['router'], $root);
            $actual = $this->routesHandledBy($this->router, $root);

            if ($actual < $expected) {
                $short[] = sprintf('%s: %d of %d routes', $manifest->name, $actual, $expected);
            }
        }

        self::assertSame(
            [],
            $short,
            "Extensions the sandbox cost routes:\n  " . implode("\n  ", $short),
        );
    }

    /**
     * The CMS is the reason the wildcard rule stopped being a tier comparison,
     * so it gets an assertion of its own rather than being one of thirteen names
     * in a list. `assertPathIsRegisterable()` refused a catch-all to Verified
     * outright while {@see \Pulsar\Extensibility\CapabilityPolicy::defaults()}
     * granted Verified the very capability the refusal named, and `boot()` threw
     * partway through the CMS's route registration: it still reached `Booted`,
     * still answered `provides.services`, and served 45 routes where it ships
     * 211. A count is what makes that visible.
     */
    #[Test]
    public function theCmsRegistersItsWholeRouteTableIncludingTheCatchAll(): void
    {
        $manifest = $this->bootstrap->registry->getManifest('pulsar/cms');
        $root = $this->rootOf($manifest);

        self::assertNotNull($root);
        self::assertGreaterThan(
            150,
            $this->routesHandledBy($this->router, $root),
            'The CMS registered far fewer routes than it ships, which is what a denial part-way '
            . 'through boot() looks like from the outside.',
        );

        $catchAll = null;

        foreach ($this->router->routes() as $route) {
            if ($route->name === 'cms.content.show') {
                $catchAll = $route;
                break;
            }
        }

        self::assertInstanceOf(Route::class, $catchAll, 'The CMS catch-all route is not in the table.');
    }

    /**
     * A catch-all is admitted for holding RouteRegisterGlobal, and is still not
     * allowed to answer a reserved path. The literal check in
     * `assertCanRegisterRoute()` cannot see this case — the route names no
     * reserved path — so the constraint is compiled into the pattern instead.
     */
    #[Test]
    public function theCmsCatchAllCannotAnswerAReservedPath(): void
    {
        $catchAll = null;

        foreach ($this->router->routes() as $route) {
            if ($route->name === 'cms.content.show') {
                $catchAll = $route;
                break;
            }
        }

        self::assertInstanceOf(Route::class, $catchAll);

        foreach (['/login', '/logout', '/admin', '/_studio', '/api', '/Login', '/ADMIN'] as $reserved) {
            self::assertNull(
                $catchAll->matchesPath($reserved),
                sprintf('The catch-all answers the reserved path %s', $reserved),
            );
        }

        // And still answers everything else, including a deeper path that only
        // begins with a reserved segment — `/admin/cms/pages` is what the CMS is
        // for, and the reserved list is exact paths for exactly that reason.
        foreach (['/about', '/blog/2026/hello', '/admin/cms/pages'] as $ordinary) {
            self::assertNotNull(
                $catchAll->matchesPath($ordinary),
                sprintf('The catch-all no longer answers %s', $ordinary),
            );
        }
    }

    /**
     * `bin/pulsar` builds an extension's command through
     * {@see ExtensionBootstrap::buildCommand()}, which finds the declaring
     * extension, charges it `CommandRegister` and constructs the command inside
     * its scope. Before that existed the console did
     * `$kernel->container()->get($commandClass)` on the real container, so a
     * command's `__construct(ContainerInterface $c)` was handed the unscoped
     * container in a process running as whoever typed `pulsar`.
     *
     * Only a CAPABILITY DENIAL fails this test, and the distinction is the point
     * rather than a convenience. A command that cannot be built here because
     * nothing in a bare boot binds `SupervisorInterface`, or because its
     * constructor takes a `string $basePath` the console supplies, is telling us
     * about the fixture; it fails identically with the sandbox switched off.
     * A command that cannot be built because its extension's tier refuses a
     * dependency is telling us about the sandbox, and is the regression this
     * test is here to catch.
     */
    #[Test]
    public function noCommandTheManifestPromisesIsDeniedByItsOwnTier(): void
    {
        $denied = [];

        foreach ($this->bootstrap->getManifests() as $manifest) {
            foreach ($manifest->provides->commands as $commandClass) {
                // Short entries such as `a11y:audit` name the command's INVOCATION,
                // not its class, and there is nothing to build from one.
                if (!str_contains($commandClass, '\\')) {
                    continue;
                }

                if (!class_exists($commandClass)) {
                    $denied[] = sprintf('%s => %s does not exist', $manifest->name, $commandClass);
                    continue;
                }

                try {
                    (void) $this->bootstrap->buildCommand($commandClass, $this->container);
                } catch (CapabilityDeniedException $denial) {
                    $denied[] = sprintf('%s => %s: %s', $manifest->name, $commandClass, $denial->getMessage());
                } catch (Throwable) {
                    // Everything else is the fixture, not the sandbox: an
                    // unbound optional collaborator, or a scalar the console
                    // passes in. Both fail the same way unsandboxed.
                }
            }
        }

        self::assertSame(
            [],
            $denied,
            "provides.commands entries their own extension's tier refuses to build:\n  "
            . implode("\n  ", $denied),
        );
    }

    /**
     * Nothing an extension put in the container is refused to that extension at
     * RESOLUTION time.
     *
     * Boot is not where most of a sandbox's damage shows up, and this is the
     * assertion that says so. Containment by construction moved the building of
     * route handlers, route middleware and CLI commands into the extension's
     * scope, so a controller's constructor parameters are now subject to
     * deny-by-default — at the moment the first request arrives, long after
     * `boot()` reported success. Five bundled controllers were in exactly that
     * state and every boot-time check in this file passed while they were:
     * `pulsar/forum`'s sign-in, registration and password-reset pages,
     * `pulsar/cms`'s business-profile settings, and `pulsar/payments`'
     * checkout. A lazy factory closure hides the same failure just as well —
     * `pulsar/payments` binds its idempotency-signing envelope as one, so the
     * first symptom would have been a 500 on the first idempotent payment.
     *
     * So every route-handler class an extension registered, and every id it
     * bound, is resolved here. A `CapabilityDeniedException` is a failure and
     * needs no reference boot to interpret: it exists only because the sandbox
     * refused something. Anything else — an unbound optional collaborator, a
     * database this fixture does not have — is the fixture, and fails
     * identically with the sandbox off.
     */
    #[Test]
    public function noHandlerOrBindingAnExtensionRegisteredIsRefusedToIt(): void
    {
        $denied = [];

        foreach ($this->resolvableIdsByExtension() as $extension => $ids) {
            foreach ($ids as $id) {
                try {
                    $_ = $this->container->get($id);
                } catch (Throwable $failure) {
                    // Matched on the instance rather than caught by type: the
                    // denial reaches here through the scope factory the
                    // container calls, which no static analyser can follow, so
                    // a typed catch reads as dead code. Anything else is the
                    // fixture, not the sandbox — see the docblock.
                    if ($failure instanceof CapabilityDeniedException) {
                        $denied[] = sprintf('%s => %s: %s', $extension, $id, $failure->getMessage());
                    }
                }
            }
        }

        self::assertSame(
            [],
            $denied,
            "Denied to the extension that registered them:\n  " . implode("\n  ", $denied),
        );
    }

    /**
     * The fifth lifecycle phase, and the one that reports rather than throws:
     * `shutdown()` catches every extension's failure so one stuck extension
     * cannot stop the others cleaning up. A test that only checked it did not
     * throw would therefore pass with every extension's shutdown denied, which
     * is why the bootstrap is given a logger that keeps what it is told.
     */
    #[Test]
    public function shutdownRunsForEveryExtensionThroughItsOwnScope(): void
    {
        $this->bootstrap->shutdown($this->container);

        self::assertSame(
            [],
            $this->errors->messages,
            "Extensions whose shutdown() failed inside their scope:\n  "
            . implode("\n  ", $this->errors->messages),
        );
    }

    /**
     * The product list this test turns on has to stay complete, or an extension
     * added later is silently never exercised — which is how the framework came
     * to ship four broken bundled products in the first place.
     */
    #[Test]
    public function everyBundledProductIsExercised(): void
    {
        $unlisted = [];

        foreach ($this->bootstrap->getManifests() as $manifest) {
            if ($manifest->kind->value !== 'product') {
                continue;
            }

            if (!in_array($manifest->name, self::PRODUCTS, true)) {
                $unlisted[] = $manifest->name;
            }
        }

        self::assertSame(
            [],
            $unlisted,
            'Bundled products missing from this test: ' . implode(', ', $unlisted),
        );
    }

    /**
     * Boot the real thing: the framework's own `config/`, its own `extensions/`,
     * and {@see ExtensionSandbox::harden()} reading the shipped
     * `config/extensions.php`, so the tiers under test are the tiers that ship.
     */
    private function boot(): void
    {
        $this->errors = self::recordingLogger();
        $booted = $this->bootWith(sandboxed: true, logger: $this->errors);

        $this->bootstrap = $booted['bootstrap'];
        $this->container = $booted['container'];
        $this->router = $booted['router'];
    }

    /**
     * The same boot with {@see ExtensionSandbox::harden()} left out — the
     * reference the differential assertions compare against.
     *
     * Memoised for the test method that asks, not for the class: a second boot
     * costs a couple of seconds and only two of these tests need one.
     *
     * @return array{bootstrap: ExtensionBootstrap, container: Container, router: Router}
     */
    private function bootUnsandboxed(): array
    {
        return $this->unsandboxed ??= $this->bootWith(sandboxed: false, logger: self::recordingLogger());
    }

    /**
     * @return array{bootstrap: ExtensionBootstrap, container: Container, router: Router}
     */
    private function bootWith(bool $sandboxed, AbstractLogger $logger): array
    {
        $bootstrap = new ExtensionBootstrap(new ExtensionRegistry(), new ExtensionLoader(), $logger);
        $bootstrap->setEnabledProducts(self::PRODUCTS);
        $bootstrap->loadFromPaths([$this->repositoryRoot . DIRECTORY_SEPARATOR . 'extensions']);

        if ($sandboxed) {
            ExtensionSandbox::harden($bootstrap, $this->repositoryRoot . DIRECTORY_SEPARATOR . 'config');
        } else {
            // Not merely "skip harden()": `Kernel::boot()` hardens any bootstrap
            // whose policy is still null, so the reference boot has to claim the
            // property or it would silently become a second sandboxed boot.
            //
            // The reference grants every extension CORE, which is the sandbox
            // off by the framework's own definition — `scopeContainer()` returns
            // the container unwrapped for Core and the proxies never see it —
            // expressed through the mechanism an operator would use, rather than
            // by reaching past the machinery under test. An EMPTY allow-list
            // would have been the opposite mistake: it caps everything at
            // Community, which is stricter than what ships.
            $tiers = [];

            foreach ($bootstrap->getManifests() as $manifest) {
                $tiers[$manifest->name] = ['tier' => 'core'];
            }

            $bootstrap->capabilityPolicy = CapabilityPolicy::defaults();
            $bootstrap->trustedExtensionsConfig = TrustedExtensionsConfig::fromArray($tiers);
        }

        $container = new Container();
        $router = new Router();

        $kernel = new Kernel(
            $container,
            $router,
            $bootstrap,
            new ConfigManager($this->repositoryRoot . DIRECTORY_SEPARATOR . 'config'),
        );

        // A full boot writes its diagnostics to stdout; the assertions read the
        // container and the router, not the log.
        ob_start();

        try {
            $kernel->boot();
        } finally {
            ob_end_clean();
        }

        return ['bootstrap' => $bootstrap, 'container' => $container, 'router' => $router];
    }

    /** @return AbstractLogger&object{messages: list<string>} */
    private static function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            /** @param array<string, mixed> $context */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if ($level === LogLevel::ERROR || $level === LogLevel::CRITICAL) {
                    $this->messages[] = (string) $message;
                }
            }
        };
    }

    /**
     * The fully-qualified type an extension ships under a manifest's short
     * service name, or null when it ships none.
     *
     * `provides.services` is written by hand and spells names short —
     * `TicketServiceInterface`, not the four-segment namespace it lives in — so
     * the name has to be resolved against something. It is resolved against the
     * extension's own FILES: the one `TicketServiceInterface.php` under its
     * directory, with the namespace read out of it. That is the same physical
     * rule {@see \Pulsar\Extensibility\Internal\ExtensionSurfaces} applies, and
     * it is what stops the host's `SettingsServiceInterface` from standing in as
     * proof that the CMS registered its own.
     *
     * A short name matching more than one file is ambiguous and is reported as
     * unresolved rather than guessed at, because a manifest whose reader has to
     * guess is a manifest that needs the full name written in it.
     */
    private function typeShippedAs(string $service, string $root): ?string
    {
        // Already fully qualified: the manifest may spell either.
        if (str_contains($service, '\\')) {
            return $this->shipsType($service, $root) ? $service : null;
        }

        $found = null;

        foreach ($this->filesNamed($service . '.php', $root) as $file) {
            $namespace = $this->namespaceOf($file);

            if ($namespace === null) {
                continue;
            }

            $candidate = $namespace . '\\' . $service;

            if (!class_exists($candidate) && !interface_exists($candidate)) {
                continue;
            }

            if ($found !== null && $found !== $candidate) {
                return null;
            }

            $found = $candidate;
        }

        return $found;
    }

    private function shipsType(string $type, string $root): bool
    {
        $file = $this->fileDeclaring($type);

        return $file !== null && str_starts_with($file, $root . '/');
    }

    /**
     * Every file with this basename under the extension's directory.
     *
     * @return list<string>
     */
    private function filesNamed(string $basename, string $root): array
    {
        $files = [];
        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($directory) as $file) {
            // Tests and vendored copies ship their own fixtures; the manifest
            // describes what the extension PROVIDES, which lives in its source.
            if ($file->getBasename() !== $basename) {
                continue;
            }

            $path = self::normalise($file->getPathname());

            if (str_contains($path, '/tests/') || str_contains($path, '/vendor/')) {
                continue;
            }

            $files[] = $path;
        }

        return $files;
    }

    private function namespaceOf(string $file): ?string
    {
        $source = file_get_contents($file);

        if ($source === false) {
            return null;
        }

        return preg_match('/^namespace\s+([^;]+);/m', $source, $matches) === 1
            ? trim($matches[1])
            : null;
    }

    /**
     * Every id worth resolving, grouped by the extension that owns it: the
     * classes its routes name as handlers, and every id it bound in the
     * container.
     *
     * Ownership is the declaring file again, the same attribution the sandbox
     * itself uses. An id whose type resolves outside every extension directory
     * belongs to the host and is nobody's business here.
     *
     * @return array<string, list<string>>
     */
    private function resolvableIdsByExtension(): array
    {
        $owned = [];

        foreach ($this->router->routes() as $route) {
            $class = self::handlerClass($route);

            if ($class !== null) {
                $owner = $this->extensionShipping($class);

                if ($owner !== null) {
                    $owned[$owner][$class] = true;
                }
            }
        }

        foreach ($this->container->getBindings() as $id) {
            $owner = $this->extensionShipping($id);

            if ($owner !== null) {
                $owned[$owner][$id] = true;
            }
        }

        return array_map(array_keys(...), $owned);
    }

    /**
     * The manifest name of the extension whose directory holds this type's
     * declaring file, or null when no extension ships it.
     */
    private function extensionShipping(string $type): ?string
    {
        $file = $this->fileDeclaring($type);

        if ($file === null) {
            return null;
        }

        foreach ($this->bootstrap->getManifests() as $manifest) {
            $root = $this->rootOf($manifest);

            if ($root !== null && str_starts_with($file, $root . '/')) {
                return $manifest->name;
            }
        }

        return null;
    }

    /**
     * The class a route's handler names, for the two forms that name one.
     */
    private static function handlerClass(Route $route): ?string
    {
        /** @var mixed $handler */
        $handler = $route->handler;

        return match (true) {
            is_string($handler) => $handler,
            is_array($handler) && isset($handler[0]) && is_string($handler[0]) => $handler[0],
            default => null,
        };
    }

    /**
     * How many routes in a table are handled by a class this extension ships.
     */
    private function routesHandledBy(Router $router, string $root): int
    {
        $count = 0;

        foreach ($router->routes() as $route) {
            $class = self::handlerClass($route);

            if ($class === null) {
                continue;
            }

            $file = $this->fileDeclaring($class);

            if ($file !== null && str_starts_with($file, $root . '/')) {
                $count++;
            }
        }

        return $count;
    }

    private function rootOf(ExtensionManifest $manifest): ?string
    {
        if ($manifest->path === '') {
            return null;
        }

        $root = realpath($manifest->path);

        return $root === false ? null : self::normalise($root);
    }

    private function fileDeclaring(string $type): ?string
    {
        if (!class_exists($type) && !interface_exists($type)) {
            return null;
        }

        $declaredIn = new ReflectionClass($type)->getFileName();

        if ($declaredIn === false) {
            return null;
        }

        $file = realpath($declaredIn);

        return $file === false ? null : self::normalise($file);
    }

    private static function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
