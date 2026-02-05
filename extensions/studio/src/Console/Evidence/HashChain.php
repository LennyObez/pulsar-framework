<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Evidence;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Security\Crypto\HmacInterface;
use SodiumException;

use function hash;

/**
 * SHA-256 evidence hash chain with optional BLAKE2b per-link MAC.
 *
 * Each link's hash chains from its predecessor using the event's canonical form.
 * The chain is publicly verifiable (no keys needed) for integrity.
 * Optional per-link MAC provides tamper-evident verification (requires key).
 */
#[Internal]
final class HashChain
{
    private const string CHAIN_SEED_INPUT = 'PULSAR_STUDIO_CHAIN_SEED';

    public function __construct(
        private readonly ?HmacInterface $hmac = null,
        private readonly ?string $chainMacKey = null,
    ) {}

    /**
     * Get the seed hash (anchor for the first link).
     */
    #[NoDiscard]
    public static function seedHash(): string
    {
        return hash('sha256', self::CHAIN_SEED_INPUT);
    }

    /**
     * Compute the next chain link for an event.
     *
     * @throws SodiumException
     */
    public function computeLink(EventEnvelope $envelope, string $previousHash): ChainLink
    {
        $currentHash = hash('sha256', $previousHash . '|' . $envelope->canonical());

        $linkMac = null;
        if ($this->chainMacKey !== null) {
            $linkMac = $this->hmac?->computeHex($currentHash, $this->chainMacKey);
        }

        return new ChainLink(
            eventId: $envelope->eventId,
            previousHash: $previousHash,
            currentHash: $currentHash,
            linkMac: $linkMac,
        );
    }

    /**
     * Verify a chain link's hash against its predecessor and canonical event data.
     */
    #[NoDiscard]
    public static function verifyLinkHash(string $previousHash, string $canonical, string $expectedHash): bool
    {
        $computed = hash('sha256', $previousHash . '|' . $canonical);

        return hash_equals($expectedHash, $computed);
    }

    /**
     * Verify a link's MAC (requires chain MAC key).
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function verifyLinkMac(string $currentHash, string $expectedMac, string $chainMacKey, HmacInterface $hmac): bool
    {
        return $hmac->verifyHex($currentHash, $expectedMac, $chainMacKey);
    }

    /**
     * Whether this chain instance can compute/verify MACs.
     */
    public function hasMacKey(): bool
    {
        return $this->chainMacKey !== null;
    }
}
