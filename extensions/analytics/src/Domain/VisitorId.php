<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;
use Pulsar\Security\Crypto\Hmac;

/**
 * Privacy-preserving visitor identifier.
 *
 * Generated via a keyed BLAKE2b hash of IP + user agent + UTC day number,
 * keyed by that day's disposable random salt (see VisitorSaltStoreInterface).
 *
 * The privacy guarantee is forward secrecy, not one-wayness of the live hash:
 * within the retention window an attacker holding the day's salt could, in
 * principle, enumerate the low-entropy IP + user-agent space and re-identify a
 * hash. That is precisely why the salt is destroyed after the window — once it
 * is gone the day's hashes can never be recomputed, from any inputs, even with
 * the application master key, so the historical data is genuinely anonymous.
 * A stable, key-derived salt (the previous design) would have left every past
 * hash brute-forceable forever.
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
