<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Closure;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;

use function get_debug_type;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Fails a framework route that never said who may reach it.
 *
 * The sibling of {@see RouteCollisionReporter}, invoked from the same point in
 * {@see \Pulsar\Core\Kernel} once every route-registering step has run, and for
 * the same reason: a routing fact that is only visible by reading the wiring
 * source is a fact nobody checks.
 *
 * The fact here is exposure. {@see \Pulsar\Auth\Middleware\AuthorizationMiddleware}
 * default-denies a route declaring no permissions, so an undeclared route is
 * open in a deployment whose pipeline omits that middleware and closed in one
 * that includes it. Which of the two a given install got was decided by
 * middleware ordering rather than by anyone, and the audit that produced this
 * class found twenty-five framework registrations in that position.
 *
 * Scope is deliberately narrow: routes the FRAMEWORK registers, identified by a
 * handler in the `Pulsar\` namespace (excluding `Pulsar\Extension\`, which is
 * an extension's own business) or a path under `/_pulsar/`. Project and
 * extension routes are not this reporter's to judge; flagging them would make
 * the signal noise and the noise would be ignored.
 *
 * Warns in production, throws in debug — a bare framework route is a defect in
 * the framework, and the developer running it is the person who can fix it.
 *
 * @internal
 */
#[Internal]
final class RouteAccessReporter
{
    /**
     * Namespaces under `Pulsar\` that this repository reserves for code the
     * framework does not ship, and whose routes are therefore not its to judge.
     *
     * `Pulsar\Extension\` belongs to an extension: it owns its own routes and
     * carries its own route-security suites (see the admin, feedback and
     * releases ones). `Pulsar\Tests\` is this repository's own test code, mapped
     * by composer's autoload-dev; its controllers stand in for an application's,
     * which is precisely the category this reporter leaves alone. Both are
     * namespace-ownership statements of the same kind, not special cases.
     *
     * @var list<non-empty-string>
     */
    private const array NOT_SHIPPED_BY_THE_FRAMEWORK = [
        'Pulsar\\Extension\\',
        'Pulsar\\Tests\\',
    ];

    /**
     * @throws RoutingException When $debug is true and a framework route declares no access.
     */
    public static function report(Router $router, ?LoggerInterface $logger, bool $debug): void
    {
        $undeclared = [];

        foreach ($router->routes as $route) {
            if (!self::isFrameworkRoute($route)) {
                continue;
            }

            if (RouteAccess::of($route) !== null) {
                continue;
            }

            $message = sprintf(
                'Undeclared framework route: %s (handler %s) states no RouteAccess, so whether an '
                . 'anonymous request reaches it depends on the deployment\'s middleware pipeline '
                . 'rather than on a decision. Register it through RouteAccessRegistrar.',
                $route->path,
                self::describeHandler($route->handler),
            );

            $undeclared[] = $message;

            $logger?->warning($message, [
                'path' => $route->path,
                'name' => $route->name,
            ]);
        }

        if ($undeclared !== [] && $debug) {
            throw RoutingException::undeclaredFrameworkRoutes($undeclared);
        }
    }

    /**
     * Whether the framework itself registered this route.
     *
     * The HANDLER decides, and the path only breaks the tie. Several extensions
     * mount under the reserved `/_pulsar/` prefix, so reading the path first
     * would drag every one of their routes into a judgement that is not this
     * reporter's to make.
     *
     * The prefix is consulted only for a closure handler, which names no class:
     * `/_pulsar/` is reserved for the framework, so it identifies the owner
     * where the handler cannot.
     */
    private static function isFrameworkRoute(Route $route): bool
    {
        $class = self::handlerClass($route->handler);

        if ($class === null) {
            return str_starts_with($route->path, '/_pulsar/');
        }

        if (!str_starts_with($class, 'Pulsar\\')) {
            return false;
        }

        foreach (self::NOT_SHIPPED_BY_THE_FRAMEWORK as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private static function handlerClass(mixed $handler): ?string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && isset($handler[0])) {
            /** @var mixed $target */
            $target = $handler[0];

            return is_string($target) ? $target : get_debug_type($target);
        }

        if ($handler instanceof Closure) {
            return null;
        }

        return get_debug_type($handler);
    }

    private static function describeHandler(mixed $handler): string
    {
        $class = self::handlerClass($handler);

        if ($class === null) {
            return 'Closure';
        }

        if (is_array($handler) && isset($handler[1]) && is_string($handler[1])) {
            return $class . '::' . $handler[1];
        }

        return $class;
    }
}
