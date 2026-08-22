<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Thrown when a tenant context is required but has not been resolved.
 * @api
 */
#[Api(since: '1.0.0')]
final class TenantContextMissingException extends RuntimeException
{
    #[NoDiscard]
    public static function forOperation(string $operation): self
    {
        return new self(sprintf('Tenant context required for operation: %s', $operation));
    }
}
