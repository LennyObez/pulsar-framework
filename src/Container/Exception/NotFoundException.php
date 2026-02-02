<?php

declare(strict_types=1);

namespace Pulsar\Container\Exception;

use Exception;
use Psr\Container\NotFoundExceptionInterface;

use function sprintf;

/**
 * Exception thrown when a requested binding is not found in the container.
 */
final class NotFoundException extends Exception implements NotFoundExceptionInterface
{
    /**
     * Create an exception for a missing binding.
     */
    public static function forId(string $id): self
    {
        return new self(sprintf('No binding found for "%s"', $id));
    }
}
