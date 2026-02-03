<?php

declare(strict_types=1);

namespace Pulsar\ErrorHandling\Exception;

use RuntimeException;

use function sprintf;

/**
 * Exception thrown for error handling subsystem failures.
 */
final class ErrorHandlingException extends RuntimeException
{
    /**
     * The renderer failed to produce output.
     */
    public static function renderFailed(string $reason): self
    {
        return new self(sprintf('Exception render failed: %s', $reason));
    }
}
