<?php

declare(strict_types=1);

namespace Pulsar\Context\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Exception for request context errors.
 * @api
 */
#[Api(since: '1.0.0')]
final class ContextException extends RuntimeException
{
    #[NoDiscard]
    public static function notAvailable(): self
    {
        return new self('Request context is not available in the current scope');
    }

    #[NoDiscard]
    public static function invalidCorrelationId(string $value): self
    {
        return new self(sprintf(
            'Invalid correlation ID: expected 32 hex characters, got "%s"',
            $value,
        ));
    }

    #[NoDiscard]
    public static function invalidCausationId(string $value): self
    {
        return new self(sprintf(
            'Invalid causation ID: expected 32 hex characters, got "%s"',
            $value,
        ));
    }
}
