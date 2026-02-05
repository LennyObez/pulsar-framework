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
    #[NoDiscard]
    public static function disabled(): self
    {
        return new self('Admin panel is disabled. Set ADMIN_ENABLED=true to activate.');
    }

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
