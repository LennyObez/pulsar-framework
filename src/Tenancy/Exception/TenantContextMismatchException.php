<?php

declare(strict_types=1);

namespace Pulsar\Tenancy\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Thrown when the active tenant context does not match the expected tenant.
 * @api
 */
#[Api(since: '1.0.0')]
final class TenantContextMismatchException extends RuntimeException
{
    #[NoDiscard]
    public static function detected(string $expected, string $actual): self
    {
        return new self(sprintf('Tenant context mismatch: expected %s, got %s', $expected, $actual));
    }
}
