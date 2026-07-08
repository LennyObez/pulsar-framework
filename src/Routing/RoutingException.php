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
     */
    #[NoDiscard]
    public static function routerLocked(): self
    {
        return new self(
            'Router is locked in strict cached mode. Register all routes before `pulsar optimize --strict`, or use non-strict mode.',
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
}
