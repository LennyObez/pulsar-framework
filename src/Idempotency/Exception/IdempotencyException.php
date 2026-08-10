<?php

declare(strict_types=1);

namespace Pulsar\Idempotency\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use RuntimeException;
use Throwable;

use function sprintf;

/**
 * Idempotency violation exceptions.
 *
 * Every factory tags the exception with an `IdempotencyErrorKind`
 * so HTTP / controller layers can map each failure to a consistent response
 * code via `match ($e->kind)` without parsing exception messages. See
 * `IdempotencyErrorKind` for the recommended HTTP-status mapping table.
 * @api
 */
#[Api(since: '1.0.0')]
final class IdempotencyException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly IdempotencyErrorKind $kind,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    #[NoDiscard]
    public static function parameterMismatch(string $key): self
    {
        return new self(
            sprintf(
                'Idempotency key "%s" was previously used with different parameters',
                $key,
            ),
            IdempotencyErrorKind::ParameterMismatch,
        );
    }

    #[NoDiscard]
    public static function concurrentClaim(string $key): self
    {
        return new self(
            sprintf(
                'Idempotency key "%s" is currently being processed',
                $key,
            ),
            IdempotencyErrorKind::ConcurrentClaim,
        );
    }

    #[NoDiscard]
    public static function invalidKey(string $reason): self
    {
        return new self(
            sprintf('Invalid idempotency key: %s', $reason),
            IdempotencyErrorKind::InvalidKey,
        );
    }

    #[NoDiscard]
    public static function commitFailed(string $key): self
    {
        return new self(
            sprintf(
                'Failed to commit idempotency result for key "%s"',
                $key,
            ),
            IdempotencyErrorKind::CommitFailed,
        );
    }

    /**
     * A stored idempotency payload failed signature verification.
     * Always treated as tampering rather than a transient error: a
     * compromised store (forged row, replayed envelope, key-rotation
     * mismatch) cannot be safely served back to the caller.
     */
    #[NoDiscard]
    public static function tamperedPayload(string $key, string $reason): self
    {
        return new self(
            sprintf(
                'Idempotency payload for key "%s" failed integrity check: %s',
                $key,
                $reason,
            ),
            IdempotencyErrorKind::TamperedPayload,
        );
    }

    /**
     * Serialisation of an idempotency payload (or deserialisation
     * of a previously-stored one) raised a low-level error such as
     * `JsonException`. The interface contract should not leak the
     * concrete `JsonException` to consumers — they have no business
     * reasoning about JSON encode/decode internals — so handlers wrap
     * it in this domain exception and chain the original via
     * `previous` for diagnostic visibility.
     */
    #[NoDiscard]
    public static function serializationFailed(string $key, Throwable $previous): self
    {
        return new self(
            sprintf('Failed to (de)serialise idempotency payload for key "%s": %s', $key, $previous->getMessage()),
            IdempotencyErrorKind::SerializationFailed,
            $previous,
        );
    }
}
