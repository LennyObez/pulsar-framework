<?php

declare(strict_types=1);

namespace Pulsar\Http\Message;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown when a buffered body exceeds the allowed size limit.
 */
#[Api(since: '1.0.0-rc.11')]
final class BodyTooLargeException extends RuntimeException
{
    /**
     * Create an exception for a body that exceeds the maximum allowed size.
     */
    #[NoDiscard]
    public static function exceedsLimit(int $size, int $maxBytes): self
    {
        return new self(sprintf(
            'Body size %d bytes exceeds maximum allowed %d bytes',
            $size,
            $maxBytes,
        ));
    }
}
