<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Broadcasting\BroadcastAuthController;
use Pulsar\Cache\CachedRoute;
use Pulsar\Cache\CachedRouteTable;
use Pulsar\Cache\CacheIntegrity;
use Pulsar\Cache\RouteCache;
use Pulsar\Cache\RouteHandler;
use Pulsar\Cache\RouteHandlerType;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\Container;
use Pulsar\Core\Boot\CachedRouteReconstructor;
use Pulsar\Core\Wiring\AssetWiring;
use Pulsar\Core\Wiring\BroadcastWiring;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Core\Wiring\DiagnosticsWiring;
use Pulsar\Core\Wiring\MetricsWiring;
use Pulsar\Core\Wiring\ResilienceWiring;
use Pulsar\Core\Wiring\ServiceWiringInterface;
use Pulsar\Core\Wiring\WiringList;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteAccess;
use Pulsar\Routing\RouteAccessRegistrar;
use Pulsar\Routing\RouteAccessReporter;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\HmacService;
use Pulsar\WebSocket\BroadcastManagerInterface as WebSocketBroadcastManagerInterface;
use Pulsar\WebSocket\ChannelAuthorizerInterface;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;
use function unserialize;

/**
 * The routes the framework registers, read back off the router.
 *
 * The audit that prompted this listed twenty-five framework registrations that
 * named no permission, four of them by name: `/_pulsar/diagnostics`,
 * `/_pulsar/rum/collect`, `/health` and `/api/i18n/{locale}.json`. The static gate in
 * FrameworkRouteAccessDeclarationTest proves the bare SHAPE is gone from the
 * source. This proves the routes that actually reach a router carry the
 * decision, and that {@see RouteAccessReporter} accepts them — the two halves
 * that make the declaration a fact about a running deployment rather than about
 * a file.
 */
#[CoversClass(DiagnosticsWiring::class)]
#[CoversClass(ResilienceWiring::class)]
#[CoversClass(AssetWiring::class)]
#[CoversClass(BroadcastWiring::class)]
#[CoversClass(MetricsWiring::class)]
#[CoversClass(RouteAccessReporter::class)]
final class FrameworkRouteAccessTest extends TestCase
{
    /**
     * The two diagnostics routes the audit named, and the decision each got.
     *
     * Diagnostics is operator-only because it publishes latency histograms and
     * error fingerprints; the RUM collector is public because it takes
     * web-vitals from visitors who have no session and therefore no credential
     * to demand.
     */
    #[Test]
    public function theDiagnosticsRoutesDeclareTheirAccess(): void
    {
        $container = new Container();
        $container->instance(MetricRegistry::class, new MetricRegistry());

        $router = $this->boot(new DiagnosticsWiring(), $container, debug: true);

        self::assertSame(
            RouteAccess::Operator,
            RouteAccess::of(self::routeFor($router, '/_pulsar/diagnostics')),
        );
        self::assertSame(
            RouteAccess::Public,
            RouteAccess::of(self::routeFor($router, '/_pulsar/rum/collect')),
        );

        self::assertNoUndeclaredFrameworkRoute($router);
    }

    /**
     * The development-only half of the diagnostics decision, proven rather than
     * asserted in a comment: neither route exists when debug is off.
     */
    #[Test]
    public function neitherDiagnosticsRouteExistsInProduction(): void
    {
        $container = new Container();
        $container->instance(MetricRegistry::class, new MetricRegistry());

        $router = $this->boot(new DiagnosticsWiring(), $container, debug: false);

        self::assertSame([], $router->routes, 'diagnostics and RUM must be absent from a production route table');
    }

    /**
     * `/health` and `/_pulsar/health` are public on purpose — a load balancer
     * carries no credential — and now say so instead of omitting it.
     */
    #[Test]
    public function bothHealthProbesAreDeclaredPublic(): void
    {
        $router = $this->boot(new ResilienceWiring(), new Container(), debug: false);

        foreach (['/health', '/_pulsar/health'] as $path) {
            $route = self::routeFor($router, $path);

            self::assertSame(RouteAccess::Public, RouteAccess::of($route), $path);
            self::assertNotNull(RouteAccess::reasonOf($route), "$path must record WHY it is public");
        }

        self::assertNoUndeclaredFrameworkRoute($router);
    }

    #[Test]
    public function theAssetRoutesAreDeclaredPublic(): void
    {
        $router = $this->boot(new AssetWiring(), new Container(), debug: false);

        $route = self::routeFor($router, '/ui/{path}');

        self::assertSame(RouteAccess::Public, RouteAccess::of($route));
        self::assertSame(['path' => '.+'], $route->constraints, 'the constraint must survive the declaration');

        self::assertNoUndeclaredFrameworkRoute($router);
    }

    /**
     * The broadcast endpoint is the one whose in-controller identity check was
     * unreachable: the controller reads the `identity` attribute, and only the
     * `auth` alias ever puts a resolved identity there. Registering through it
     * is what makes the controller's deny-by-default able to distinguish a
     * logged-in caller from an anonymous one at all.
     */
    #[Test]
    public function theBroadcastAuthRouteIsRegisteredBehindTheAuthAlias(): void
    {
        $container = $this->broadcastContainer();
        $registry = new MiddlewareRegistry();
        $registry->alias(RouteAccessRegistrar::AUTH_ALIAS, AuthorizationMiddleware::class);

        $router = $this->boot(new BroadcastWiring(), $container, debug: false, middlewareRegistry: $registry);

        $route = self::routeFor($router, '/broadcasting/auth');

        self::assertSame(RouteAccess::Authenticated, RouteAccess::of($route));
        self::assertContains(RouteAccessRegistrar::AUTH_ALIAS, $route->middleware);
        self::assertSame(
            [RouteAccessRegistrar::ANY_AUTHENTICATED],
            $route->attributes['permissions'] ?? null,
            'AuthorizationMiddleware default-denies an empty permission list',
        );
        self::assertSame(
            [BroadcastAuthController::class, 'authenticate'],
            $route->handler,
        );
    }

    /**
     * And when authentication is switched off entirely, the same endpoint is not
     * registered at all rather than served with its permission list unenforced.
     */
    #[Test]
    public function theBroadcastAuthRouteIsAbsentWhenNoGuardExists(): void
    {
        $router = $this->boot(new BroadcastWiring(), $this->broadcastContainer(), debug: false);

        self::assertSame([], $router->routes, 'a guarded route must not exist without its guard');
    }

    /**
     * The OpenMetrics exporter publishes every request count, latency histogram
     * and error fingerprint the process holds. It is operator-only, and the
     * credential is the Bearer token a scraper can actually carry -- so the
     * route declares Operator without naming the `auth` alias, which would 401
     * the scraper it exists for.
     */
    #[Test]
    public function theMetricsExporterIsDeclaredOperatorOnly(): void
    {
        $router = $this->boot(new MetricsWiring(), new Container(), debug: false, metricsExporter: true);

        $route = self::routeFor($router, '/metrics');

        self::assertSame(RouteAccess::Operator, RouteAccess::of($route));
        self::assertNotContains(RouteAccessRegistrar::AUTH_ALIAS, $route->middleware);
        self::assertNotNull(RouteAccess::reasonOf($route));

        self::assertNoUndeclaredFrameworkRoute($router);
    }

    /**
     * Production serves from a compiled route table, so the declaration only
     * counts if it survives `optimize --strict`.
     *
     * A route table that comes back from the cache with its permissions and its
     * access decision stripped is the same defect one layer down: the routes
     * still dispatch, and the statements qualifying them are gone. The enum is
     * serialized in PHP's `E:` form, which the cache's deserialization allowlist
     * derives from the payload itself, so the case comes back as the case and
     * not as an incomplete class.
     */
    #[Test]
    public function theDeclarationSurvivesTheCompiledRouteCache(): void
    {
        $router = new Router();

        new RouteAccessRegistrar($router, new MiddlewareRegistry())->publicRoute(
            [Method::GET],
            '/_pulsar/health',
            [HealthProbeStandIn::class, 'show'],
            'pulsar.health',
            'load-balancer probe',
        );

        // compile() never touches the integrity collaborator; it is only needed
        // by the write path, which this test deliberately does not exercise.
        $integrity = new CacheIntegrity(new HmacService(), bin2hex(random_bytes(16)));
        $compiled = new RouteCache($integrity)->compile($router->routes, []);

        /** @var CachedRouteTable $table */
        $table = unserialize($compiled['serialized'], [
            'allowed_classes' => [
                CachedRouteTable::class,
                CachedRoute::class,
                RouteHandler::class,
                RouteHandlerType::class,
                Method::class,
                RouteAccess::class,
            ],
        ]);

        $restored = CachedRouteReconstructor::reconstruct($table->routes);

        // reconstruct() answers null for a cache it cannot read, and that is a
        // different outcome from an empty table: asserting the count first would
        // report "expected 1, got 0" for a payload that was never parsed.
        self::assertNotNull($restored, 'the cached table did not reconstruct');
        self::assertCount(1, $restored);
        self::assertSame(RouteAccess::Public, RouteAccess::of($restored[0]));
        self::assertSame('load-balancer probe', RouteAccess::reasonOf($restored[0]));
    }

    private function broadcastContainer(): Container
    {
        $container = new Container();
        $container->instance(
            WebSocketBroadcastManagerInterface::class,
            $this->createStub(WebSocketBroadcastManagerInterface::class),
        );
        $container->instance(
            ChannelAuthorizerInterface::class,
            $this->createStub(ChannelAuthorizerInterface::class),
        );

        return $container;
    }

    private function boot(
        ServiceWiringInterface $wiring,
        Container $container,
        bool $debug,
        ?MiddlewareRegistry $middlewareRegistry = null,
        bool $metricsExporter = false,
    ): Router {
        $router = new Router();
        $configManager = self::configManager($debug, $metricsExporter);

        $wiring->wire(
            $container,
            $configManager,
            new MiddlewarePipeline($container),
            $middlewareRegistry ?? new MiddlewareRegistry(),
            $router,
        );

        return $router;
    }

    private static function routeFor(Router $router, string $path): Route
    {
        foreach ($router->routes as $route) {
            if ($route->path === $path) {
                return $route;
            }
        }

        self::fail("No route registered at $path");
    }

    /** The reporter must accept what the wirings produce, or boot would abort. */
    private static function assertNoUndeclaredFrameworkRoute(Router $router): void
    {
        RouteAccessReporter::report($router, null, debug: true);

        self::assertNotSame([], $router->routes);
    }

    private static function configManager(bool $debug, bool $metricsExporter = false): ConfigManager
    {
        $path = sys_get_temp_dir() . '/pulsar_route_access_' . bin2hex(random_bytes(6));
        @mkdir($path, 0o755, true);

        $debugLiteral = $debug ? 'true' : 'false';

        file_put_contents(
            $path . '/app.php',
            '<?php return ["name" => "Test", "env" => "testing", "debug" => ' . $debugLiteral
                . ', "timezone" => "UTC", "locale" => "en"];',
        );
        $metrics = $metricsExporter
            ? ', "metrics" => ["enabled" => true, "exporters" => ["openmetrics" => ["enabled" => true]]]'
            : '';

        file_put_contents(
            $path . '/observability.php',
            '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]'
                . $metrics . '];',
        );
        file_put_contents(
            $path . '/security.php',
            '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => []];',
        );
        file_put_contents(
            $path . '/resilience.php',
            '<?php return ["enabled" => true, "health_check" => ["enabled" => true]];',
        );

        $manager = new ConfigManager($path);
        ConfigLoaderRegistrar::register($manager, WiringList::default());
        $manager->load();

        return $manager;
    }
}

/** Handler stand-in for the cache round trip; never invoked. */
final class HealthProbeStandIn
{
    public function show(): string
    {
        return 'ok';
    }
}
