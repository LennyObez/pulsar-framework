<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Pulsar\Http\Controller\AssetController;
use Pulsar\Http\Controller\HealthController;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteAccess;
use Pulsar\Routing\RouteAccessRegistrar;
use Pulsar\Routing\RouteAccessReporter;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;
use Stringable;

/**
 * The runtime half of the rule the source scan enforces statically.
 *
 * A source scan cannot see a route the framework builds from a config value, and
 * it cannot see one an upgrade reintroduces in a compiled cache. This reads the
 * assembled route table at the end of boot and refuses to let a framework route
 * through without a declaration — warning in production, failing closed in debug,
 * exactly as the sibling collision reporter does.
 */
#[CoversClass(RouteAccessReporter::class)]
final class RouteAccessReporterTest extends TestCase
{
    /**
     * The behaviour that matters: an undeclared framework route stops a debug
     * boot. Without this the declaration would be documentation.
     */
    #[Test]
    public function anUndeclaredFrameworkRouteFailsADebugBoot(): void
    {
        $router = new Router();
        $router->get('/_pulsar/newly-bare', [AssetController::class, 'ui']);

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessageMatches('#/_pulsar/newly-bare#');

        RouteAccessReporter::report($router, null, debug: true);
    }

    /**
     * In production the same route warns instead of aborting: refusing to serve
     * a running deployment over a missing annotation would be a worse outcome
     * than telling its operator about it.
     */
    #[Test]
    public function theSameRouteOnlyWarnsInProduction(): void
    {
        $router = new Router();
        $router->get('/_pulsar/newly-bare', [AssetController::class, 'ui']);

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            /**
             * @param mixed              $level
             * @param string|Stringable $message
             * @param array<mixed>       $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };

        RouteAccessReporter::report($router, $logger, debug: false);

        self::assertCount(1, $logger->lines);
        self::assertStringContainsString('/_pulsar/newly-bare', $logger->lines[0]);
    }

    /** A declared route passes, or the reporter would block every boot. */
    #[Test]
    public function aDeclaredFrameworkRoutePasses(): void
    {
        $router = new Router();

        new RouteAccessRegistrar($router, new MiddlewareRegistry())->publicRoute(
            [Method::GET],
            '/_pulsar/health',
            [HealthController::class, '__invoke'],
            'pulsar.health',
            'load-balancer probe',
        );

        RouteAccessReporter::report($router, null, debug: true);

        self::assertSame(RouteAccess::Public, RouteAccess::of($router->routes[0]));
    }

    /**
     * An application's own routes are not this reporter's to judge. Flagging
     * them would bury the framework signal in noise from every project, and a
     * signal nobody reads is the state this whole exercise started from.
     */
    #[Test]
    public function anApplicationRouteIsNotJudged(): void
    {
        $router = new Router();
        $router->add(new Route([Method::GET], '/orders', ['App\\Controller\\OrderController', 'index']));

        RouteAccessReporter::report($router, null, debug: true);

        self::assertCount(1, $router->routes);
    }

    /**
     * Nor is an extension's. Extensions own their own routes and carry their own
     * route-security suites; `Pulsar\Extension\` is deliberately carved out of
     * the `Pulsar\` prefix that identifies framework code.
     */
    #[Test]
    public function anExtensionRouteIsNotJudged(): void
    {
        $router = new Router();
        $router->add(new Route([Method::GET], '/feedback', ['Pulsar\\Extension\\Feedback\\Api', 'index']));

        RouteAccessReporter::report($router, null, debug: true);

        self::assertCount(1, $router->routes);
    }

    /**
     * Nor is this repository's own test code. Fixtures under `Pulsar\Tests\`
     * stand in for an application's controllers, which is exactly the category
     * left alone above; without this carve-out every kernel-booting test that
     * registers a class-based route would have to invent an application
     * namespace to satisfy a check that was never about it.
     */
    #[Test]
    public function aTestFixtureRouteIsNotJudged(): void
    {
        $router = new Router();
        $router->add(new Route([Method::GET], '/fixture', [self::class, 'aTestFixtureRouteIsNotJudged']));

        RouteAccessReporter::report($router, null, debug: true);

        self::assertCount(1, $router->routes);
    }

    /**
     * A closure handler under `/_pulsar/` is still framework code: the path
     * prefix is reserved, so it identifies the owner where the handler cannot.
     */
    #[Test]
    public function aClosureHandlerUnderThePulsarPrefixIsStillJudged(): void
    {
        $router = new Router();
        $router->get('/_pulsar/metrics', static fn(): string => 'ok');

        $this->expectException(RoutingException::class);

        RouteAccessReporter::report($router, null, debug: true);
    }
}
