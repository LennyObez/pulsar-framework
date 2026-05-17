<?php

declare(strict_types=1);

namespace Pulsar\Observability\ErrorTracking\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown for error tracking system errors.
 * @api
 */
#[Api(since: '1.0.0')]
final class ErrorTrackingException extends RuntimeException
{
    #[NoDiscard]
    public static function maxGroupsExceeded(int $max): self
    {
        return new self(sprintf(
            'Maximum number of error groups (%d) exceeded',
            $max,
        ));
    }
}
