<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown when a service discovery operation fails.
 */
#[Api(since: '1.0.0')]
class ServiceDiscoveryException extends RuntimeException
{
    #[NoDiscard]
    public static function serviceNotFound(string $name): self
    {
        return new self(sprintf('Service "%s" is not registered in the discovery registry', $name));
    }

    #[NoDiscard]
    public static function noHealthyInstances(string $name): self
    {
        return new self(sprintf('No healthy instances available for service "%s"', $name));
    }
}
