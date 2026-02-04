<?php

declare(strict_types=1);

namespace Pulsar\Container\Exception;

use Exception;
use NoDiscard;
use Psr\Container\NotFoundExceptionInterface;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Exception thrown when a requested binding is not found in the container.
 */
#[Api(since: '1.0.0')]
final class NotFoundException extends Exception implements NotFoundExceptionInterface
{
    /**
     * Create an exception for a missing binding.
     */
    #[NoDiscard]
    public static function forId(string $id): self
    {
        return new self(sprintf('No binding found for "%s"', $id));
    }
}
