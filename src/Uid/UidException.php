<?php

declare(strict_types=1);

namespace Pulsar\Uid;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function sprintf;

/**
 * Domain exception for UID parsing and generation failures.
 *
 * Static factories make the failure mode explicit so callers can
 * pattern-match without parsing exception messages.
 * @api
 */
#[Api(since: '1.0.0')]
final class UidException extends InvalidArgumentException
{
    public static function invalidUuidV7(string $value): self
    {
        return new self(sprintf(
            'Value is not a valid UUIDv7: "%s". Expected canonical hyphenated form with version=7 and variant=10xx.',
            $value,
        ));
    }

    public static function timestampOutOfRange(int $timestamp): self
    {
        return new self(sprintf(
            'UUIDv7 timestamp out of range: %d. Must fit in 48 bits (0..281474976710655 unix milliseconds).',
            $timestamp,
        ));
    }

    public static function invalidByteLength(int $length): self
    {
        return new self(sprintf(
            'Expected 16 bytes for a UUID, got %d.',
            $length,
        ));
    }
}
