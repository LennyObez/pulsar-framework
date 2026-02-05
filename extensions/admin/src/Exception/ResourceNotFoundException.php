<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Exception;

use NoDiscard;

use function sprintf;

/**
 * Thrown when a requested resource or record is not found.
 */
final class ResourceNotFoundException extends AdminException
{
    #[NoDiscard]
    public static function resource(string $name): self
    {
        return new self(sprintf('Resource "%s" not found', $name));
    }

    #[NoDiscard]
    public static function record(string $resourceName, string $id): self
    {
        return new self(sprintf('Record "%s" not found in resource "%s"', $id, $resourceName));
    }
}
