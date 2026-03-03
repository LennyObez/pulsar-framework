<?php

declare(strict_types=1);

namespace Pulsar\Idempotency\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;

use function sprintf;

/**
 * Idempotency violation exceptions.
 */
#[Api(since: '1.0.0')]
final class IdempotencyException extends RuntimeException
{
    #[NoDiscard]
    public static function parameterMismatch(string $key): self
    {
        return new self(sprintf(
            'Idempotency key "%s" was previously used with different parameters',
            $key,
        ));
    }

    #[NoDiscard]
    public static function concurrentClaim(string $key): self
    {
        return new self(sprintf(
            'Idempotency key "%s" is currently being processed',
            $key,
        ));
    }

    #[NoDiscard]
    public static function invalidKey(string $reason): self
    {
        return new self(sprintf('Invalid idempotency key: %s', $reason));
    }

    #[NoDiscard]
    public static function commitFailed(string $key): self
    {
        return new self(sprintf(
            'Failed to commit idempotency result for key "%s"',
            $key,
        ));
    }

    /**
     * F21.3: a stored idempotency payload failed signature verification.
     * Always treated as tampering rather than a transient error: a
     * compromised store (forged row, replayed envelope, key-rotation
     * mismatch) cannot be safely served back to the caller.
     */
    #[NoDiscard]
    public static function tamperedPayload(string $key, string $reason): self
    {
        return new self(sprintf(
            'Idempotency payload for key "%s" failed integrity check: %s',
            $key,
            $reason,
        ));
    }
}
