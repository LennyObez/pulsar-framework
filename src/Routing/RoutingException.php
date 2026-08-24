<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Exception;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Http\Method;

use function implode;
use function sprintf;

/**
 * Exception thrown when routing fails.
 * @api
 */
#[Api(since: '1.0.0')]
final class RoutingException extends Exception
{
    /** @var list<Method> */
    public private(set) array $allowedMethods = [];

    /**
     * Create a "not found" exception.
     */
    #[NoDiscard]
    public static function notFound(string $path): self
    {
        return new self(
            sprintf('No route found for path "%s"', $path),
            404,
        );
    }

    /**
     * Create a "method not allowed" exception.
     *
     * @param list<Method> $allowedMethods
     */
    #[NoDiscard]
    public static function methodNotAllowed(
        string $path,
        Method $method,
        array $allowedMethods,
    ): self {
        $exception = new self(
            sprintf(
                'Method "%s" not allowed for path "%s". Allowed: %s',
                $method->value,
                $path,
                implode(', ', array_map(fn(Method $m) => $m->value, $allowedMethods)),
            ),
            405,
        );

        $exception->allowedMethods = $allowedMethods;

        return $exception;
    }

    /**
     * The route index and the route table disagree: a route matching the
     * requested path and accepting the requested method exists, and the indexed
     * lookup did not return it.
     *
     * Raised from {@see Router::match()}'s cold scan, which is otherwise the
     * 405 detector. It is deliberately not a 405: the requested method IS
     * allowed on the matched route, so an `Allow` header built from that route
     * would contain the method the response claims to reject, and a client or a
     * cache acting on it would be acting on a contradiction. It is deliberately
     * not a silent recovery either — the cold scan sees routes the match tables
     * exclude on purpose (a later registration shadowed by an earlier one), so
     * serving from it would resurrect a route the collision rules retired.
     *
     * A 500 is the honest answer: the request is well-formed and the router is
     * not, which is a defect in the framework or in a route the framework
     * indexed wrongly, and it must be visible rather than dressed as traffic.
     */
    #[NoDiscard]
    public static function routeIndexInconsistent(string $path, Method $method, string $routePath): self
    {
        return new self(
            sprintf(
                'Route index inconsistent: request "%s %s" matches registered route "%s", which accepts that '
                . 'method, but the indexed lookup did not return it. The route is registered and unroutable.',
                $method->value,
                $path,
                $routePath,
            ),
            500,
        );
    }

    /**
     * Create a "not implemented" exception for an unrecognized HTTP method.
     *
     * RFC 9110 §15.6.2: 501 signals the server does not support the method for
     * any resource (e.g. a PROPFIND request to a server that only speaks the
     * standard verbs), distinct from 405 (a known method not allowed on a path).
     */
    #[NoDiscard]
    public static function notImplemented(string $method): self
    {
        return new self(
            sprintf('HTTP method "%s" is not implemented', $method),
            501,
        );
    }

    /**
     * Get the Allow header value.
     */
    public function getAllowHeader(): string
    {
        return implode(', ', array_map(fn(Method $m) => $m->value, $this->allowedMethods));
    }

    /**
     * Check if this is a "not found" exception.
     */
    public function isNotFound(): bool
    {
        return $this->getCode() === 404;
    }

    /**
     * Check if this is a "method not allowed" exception.
     */
    public function isMethodNotAllowed(): bool
    {
        return $this->getCode() === 405;
    }

    /**
     * Check if this is a "not implemented" exception (unrecognized HTTP method).
     */
    public function isNotImplemented(): bool
    {
        return $this->getCode() === 501;
    }

    /**
     * Create a "router locked" exception for strict cache mode.
     *
     * Raised only for a registration the cached table does not already contain
     * — an identical replay is accepted, see {@see \Pulsar\Routing\Router::add()}
     * — so the route that provoked it is the drift, and naming it is the whole
     * diagnosis. Without the path the operator was told a cache was stale and
     * left to find out which of several hundred routes said so.
     */
    #[NoDiscard]
    public static function routerLocked(?string $path = null): self
    {
        $subject = $path === null
            ? 'A route'
            : sprintf('Route "%s"', $path);

        return new self(
            sprintf(
                '%s is not in the strict route cache this deployment booted from. The router is '
                . 'locked to that table because the table is authoritative, so the deployed code '
                . 'and its cache disagree: re-run `pulsar optimize --strict`, or boot without a '
                . 'strict cache.',
                $subject,
            ),
            423,
        );
    }

    /**
     * Create a "route collisions detected" exception used to fail closed in debug
     * mode when a later route (typically an extension) shadows an already
     * registered route for the same method and path.
     *
     * @param list<string> $messages One human-readable description per collision.
     */
    #[NoDiscard]
    public static function routeCollisions(array $messages): self
    {
        return new self(
            "Route collisions detected at boot (debug mode fails closed):\n - " . implode("\n - ", $messages),
            500,
        );
    }

    #[NoDiscard]
    public static function invalidHandler(string $class): self
    {
        return new self(sprintf('Controller "%s" must be callable or specify a method', $class));
    }

    #[NoDiscard]
    public static function nonCallableHandler(): self
    {
        return new self('Invalid route handler');
    }

    #[NoDiscard]
    public static function unexpectedReturnType(string $type): self
    {
        return new self(sprintf('Handler must return a Response or string, got %s', $type));
    }

    /**
     * A framework route declared {@see \Pulsar\Routing\RouteAccess::Authenticated}
     * with an empty permission list.
     *
     * {@see \Pulsar\Auth\Middleware\AuthorizationMiddleware} reads an empty
     * list as deny-everyone, so the registration would produce a route no caller
     * can ever reach — a broken feature wearing the appearance of a guarded one.
     * Name the permission, or use
     * {@see \Pulsar\Routing\RouteAccessRegistrar::ANY_AUTHENTICATED} when the
     * grant really is "any authenticated identity".
     */
    #[NoDiscard]
    public static function accessDeclarationWithoutPermission(string $path): self
    {
        return new self(
            sprintf(
                'Route "%s" is declared as requiring authentication but names no permission. '
                . 'AuthorizationMiddleware default-denies an empty permission list, so the route '
                . 'would be unreachable for every caller.',
                $path,
            ),
            500,
        );
    }

    /**
     * Framework routes reached the end of boot without declaring who may reach
     * them; see {@see \Pulsar\Routing\RouteAccessReporter}.
     *
     * @param list<string> $messages One human-readable description per route.
     */
    #[NoDiscard]
    public static function undeclaredFrameworkRoutes(array $messages): self
    {
        return new self(
            'Framework routes declaring no access detected at boot (debug mode fails closed):' . PHP_EOL . ' - '
                . implode(PHP_EOL . ' - ', $messages),
            500,
        );
    }
}
