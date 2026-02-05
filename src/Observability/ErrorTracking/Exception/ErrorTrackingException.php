<?php

declare(strict_types=1);

namespace Pulsar\Observability\ErrorTracking\Exception;

use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown for error tracking system errors.
 */
#[Api]
final class ErrorTrackingException extends RuntimeException
{
    public static function maxGroupsExceeded(int $max): self
    {
        return new self(sprintf(
            'Maximum number of error groups (%d) exceeded',
            $max,
        ));
    }
}
