<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Security;

use Pulsar\Api\Api;

use function in_array;

/**
 * Immutable permission set for a gRPC service identity.
 *
 * Encapsulates the set of fully qualified gRPC methods an identity is
 * allowed to call. Supports wildcard access via ["*"].
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ServicePermission
{
    /**
     * @param list<string> $allowedMethods Fully qualified gRPC methods, or ["*"] for unrestricted access
     */
    public function __construct(
        private array $allowedMethods,
    ) {}

    /**
     * Create a permission set that grants access to all methods.
     */
    public static function unrestricted(): self
    {
        return new self(['*']);
    }

    /**
     * Create a permission set from an explicit list of allowed methods.
     *
     * @param list<string> $methods
     */
    public static function restricted(array $methods): self
    {
        return new self($methods);
    }

    /**
     * Whether this permission set allows the given fully qualified method.
     */
    public function allows(string $method): bool
    {
        if ($this->allowedMethods === ['*']) {
            return true;
        }

        return in_array($method, $this->allowedMethods, true);
    }

    /**
     * Whether this is an unrestricted (wildcard) permission set.
     */
    public function isUnrestricted(): bool
    {
        return $this->allowedMethods === ['*'];
    }

    /**
     * Get the allowed methods list.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return $this->allowedMethods;
    }
}
