<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Exception;

use NoDiscard;
use RuntimeException;

/**
 * Idempotency violation exceptions.
 */
final class IdempotencyException extends RuntimeException
{
    #[NoDiscard]
    public static function parameterMismatch(string $key): self
    {
        return new self(\sprintf(
            'Idempotency key "%s" was previously used with different parameters',
            $key,
        ));
    }

    #[NoDiscard]
    public static function concurrentClaim(string $key): self
    {
        return new self(\sprintf(
            'Idempotency key "%s" is currently being processed',
            $key,
        ));
    }

    #[NoDiscard]
    public static function invalidKey(string $reason): self
    {
        return new self(\sprintf('Invalid idempotency key: %s', $reason));
    }

    #[NoDiscard]
    public static function commitFailed(string $key): self
    {
        return new self(\sprintf(
            'Failed to commit idempotency result for key "%s"',
            $key,
        ));
    }
}
