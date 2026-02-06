<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Exception;
use Pulsar\Api\Api;
use Pulsar\Http\Method;

use function sprintf;

/**
 * Exception thrown when routing fails.
 */
#[Api]
final class RoutingException extends Exception
{
    /** @var list<Method> */
    public private(set) array $allowedMethods = [];

    /**
     * Create a "not found" exception.
     */
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
     * Create a "router locked" exception for strict cache mode.
     */
    public static function routerLocked(): self
    {
        return new self(
            'Router is locked in strict cached mode. Register all routes before `pulsar optimize --strict`, or use non-strict mode.',
            423,
        );
    }
}
