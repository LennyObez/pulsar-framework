<?php

declare(strict_types=1);

namespace Pulsar\Idempotency;

use NoDiscard;
use Pulsar\Api\Api;
use SodiumException;

use function bin2hex;
use function pack;
use function sodium_crypto_generichash;
use function strlen;

/**
 * Value object representing an idempotency key.
 *
 * Can be created from a raw string or deterministically derived from
 * component parts via libsodium BLAKE2b hashing.
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
     * Create a deterministic key by BLAKE2b hashing length-prefixed parts.
     *
     * Useful for deriving idempotency keys from composite identifiers
     * (e.g., user ID + operation + request parameters). Per ADR-0006 the
     * framework uses libsodium primitives only.
     *
     * Each part is length-prefixed with a 4-byte big-endian header before
     * concatenation, so `fromParts('a', 'bc')` and `fromParts('ab', 'c')`
     * cannot collide. A naive `implode('|', $parts)` would have produced
     * `'a|bc'` and `'ab|c'`, but a delimiter-based scheme also collides on
     * inputs that themselves contain the delimiter (e.g. `['a|b', 'c']`
     * vs `['a', 'b', 'c']`); length-prefixing eliminates both classes.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function fromParts(string ...$parts): self
    {
        $canonical = '';
        foreach ($parts as $part) {
            $canonical .= pack('N', strlen($part)) . $part;
        }

        return new self(bin2hex(sodium_crypto_generichash($canonical, '', 32)));
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
