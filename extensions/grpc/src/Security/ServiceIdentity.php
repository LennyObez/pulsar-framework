<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Security;

use Pulsar\Api\Api;

/**
 * Resolved service identity from mTLS certificate validation.
 *
 * Represents a trusted peer service identified by its certificate SAN,
 * with a trust level and a permission set controlling method access.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ServiceIdentity
{
    public ServicePermission $permissions;

    /**
     * @param string $name           The service name mapped from the certificate SAN
     * @param string $trustLevel     Trust classification (e.g. "internal", "external", "partner")
     * @param list<string> $allowedMethods Fully qualified gRPC methods this identity may call, or ["*"] for all
     */
    public function __construct(
        public string $name,
        public string $trustLevel,
        public array $allowedMethods,
    ) {
        $this->permissions = new ServicePermission($allowedMethods);
    }

    /**
     * Whether this identity is permitted to invoke the given fully qualified method.
     */
    public function isMethodAllowed(string $method): bool
    {
        return $this->permissions->allows($method);
    }
}
