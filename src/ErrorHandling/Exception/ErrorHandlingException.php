<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown for error handling subsystem failures.
 * @api
 */
#[Api(since: '1.0.0')]
final class ErrorHandlingException extends RuntimeException
{
    /**
     * The renderer failed to produce output.
     */
    #[NoDiscard]
    public static function renderFailed(string $reason): self
    {
        return new self(sprintf('Exception render failed: %s', $reason));
    }
}
