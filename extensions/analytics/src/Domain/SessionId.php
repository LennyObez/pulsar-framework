<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use Pulsar\Api\Api;
use Pulsar\Security\Crypto\Hmac;

/**
 * Session identifier derived from visitor ID and entry timestamp bucket.
 *
 * Uses keyed BLAKE2b to produce a deterministic, privacy-preserving session ID.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SessionId
{
    private function __construct(
        public string $hash,
    ) {}

    /**
     * Generate a session ID from visitor ID and timestamp bucket.
     *
     * The timestamp bucket groups sessions by their start time.
     */
    public static function generate(VisitorId $visitorId, int $timestampBucket, string $key): self
    {
        return new self(
            hash: Hmac::computeHex($visitorId->hash . '|' . $timestampBucket, $key),
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
