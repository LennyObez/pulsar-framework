<?php

declare(strict_types=1);

namespace Pulsar\Idempotency;

use function hash;
use function implode;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Value object representing an idempotency key.
 *
 * Can be created from a raw string or deterministically derived from
 * component parts via SHA-256 hashing.
 */
#[Api(since: '1.0.0')]
final readonly class IdempotencyKey
{
    public function __construct(
        public string $value,
    ) {}

    /**
     * Create from a raw string key.
     */
    #[NoDiscard]
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /**
     * Create a deterministic key by SHA-256 hashing joined parts.
     *
     * Useful for deriving idempotency keys from composite identifiers
     * (e.g., user ID + operation + request parameters).
     */
    #[NoDiscard]
    public static function fromParts(string ...$parts): self
    {
        $joined = implode('|', $parts);

        return new self(hash('sha256', $joined));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
