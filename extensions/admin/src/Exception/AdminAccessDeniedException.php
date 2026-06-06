<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Exception;

use NoDiscard;
use Pulsar\Extension\Admin\Domain\ResourceOperation;

use function sprintf;

/**
 * Thrown when the current identity lacks permission for an admin operation.
 */
final class AdminAccessDeniedException extends AdminException
{
    #[NoDiscard]
    public static function insufficientRole(string $required): self
    {
        return new self(sprintf('Access denied: role "%s" is required', $required));
    }

    #[NoDiscard]
    public static function operationDenied(string $resourceName, ResourceOperation $operation): self
    {
        return new self(sprintf(
            'Access denied: cannot %s on resource "%s"',
            $operation->value,
            $resourceName,
        ));
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    #[NoDiscard]
    public static function twoFactorRequired(): self
    {
        return new self('Access denied: two-factor authentication is required for admin access');
    }
}
