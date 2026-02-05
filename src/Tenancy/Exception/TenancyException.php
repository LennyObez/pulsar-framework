<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for multi-tenancy errors.
 */
#[Api]
final class TenancyException extends RuntimeException
{
    /**
     * No tenant could be resolved from the request.
     */
    public static function tenantNotResolved(): self
    {
        return new self('Tenant could not be resolved from the current request');
    }

    /**
     * The resolved tenant identifier does not match any configured tenant.
     */
    public static function tenantNotFound(string $identifier): self
    {
        return new self(sprintf('Tenant not found: "%s"', $identifier));
    }

    /**
     * Tenancy configuration is invalid.
     */
    public static function invalidConfiguration(string $reason): self
    {
        return new self(sprintf('Invalid tenancy configuration: %s', $reason));
    }
}
