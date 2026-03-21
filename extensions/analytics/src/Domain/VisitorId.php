<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;
use Pulsar\Security\Crypto\Hmac;

/**
 * Privacy-preserving visitor identifier.
 *
 * Generated via keyed BLAKE2b hash of IP + user agent + UTC day number using
 * a KDF-derived key. Cannot be reversed even if inputs are known, and changes
 * daily to prevent long-term tracking.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class VisitorId
{
    private function __construct(
        public string $hash,
    ) {}

    /**
     * Generate a visitor ID from IP address, user agent, and UTC day number.
     *
     * The day number (days since Unix epoch) ensures hashes rotate daily
     * without requiring subkey rotation.
     */
    public static function generate(string $ip, string $userAgent, string $key, int $dayNumber): self
    {
        return new self(
            hash: Hmac::computeHex($ip . '|' . $userAgent . '|' . $dayNumber, $key),
        );
    }

    /**
     * Reconstruct from a stored hash value.
     */
    public static function fromHash(string $hash): self
    {
        return new self(hash: $hash);
    }

    public function toString(): string
    {
        return $this->hash;
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }
}
