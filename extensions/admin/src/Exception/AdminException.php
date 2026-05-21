<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Exception;

use NoDiscard;
use RuntimeException;

use function sprintf;

/**
 * Base exception for admin panel errors.
 */
class AdminException extends RuntimeException
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    #[NoDiscard]
    public static function disabled(): self
    {
        return new self('Admin panel is disabled. Set ADMIN_ENABLED=true to activate.');
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    #[NoDiscard]
    public static function invalidConfiguration(string $detail): self
    {
        return new self(sprintf('Invalid admin configuration: %s', $detail));
    }

    #[NoDiscard]
    public static function resourceAlreadyRegistered(string $name): self
    {
        return new self(sprintf('Resource "%s" is already registered', $name));
    }
}
