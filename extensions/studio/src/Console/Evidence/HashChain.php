<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Evidence;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Security\Crypto\HmacInterface;
use SodiumException;

use function bin2hex;
use function hash;
use function sodium_crypto_kdf_derive_from_key;

use const SODIUM_CRYPTO_KDF_KEYBYTES;

/**
 * SHA-256 evidence hash chain with optional BLAKE2b per-link MAC.
 *
 * Each link's hash chains from its predecessor using the event's canonical form.
 * The chain is publicly verifiable (no keys needed) for integrity.
 * Optional per-link MAC provides tamper-evident verification (requires key).
 *
 * The chain seed is derived from the master key via KDF with a Studio-specific
 * context, preventing prediction by attackers without key material.
 * The derived seed is stored in studio_meta on first boot for chain continuity.
 */
#[Internal]
final class HashChain
{
    /**
     * Sub-key ID for Studio chain seed derivation.
     */
    private const int CHAIN_SEED_SUB_KEY_ID = 15;

    /**
     * KDF context for chain seed (exactly 8 bytes).
     */
    private const string CHAIN_SEED_CONTEXT = 'stu_seed';

    /**
     * Fallback constant for environments without master key (dev/testing).
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    private const string CHAIN_SEED_FALLBACK = 'PULSAR_STUDIO_CHAIN_SEED';

    public function __construct(
        private readonly ?HmacInterface $hmac = null,
        private readonly ?string $chainMacKey = null,
    ) {}

    /**
     * Get the seed hash (anchor for the first link).
     *
     * Uses the fallback constant when no master-key-derived seed is available.
     * Production deployments should use {@see deriveSeedFromMasterKey()} at boot
     * and store the result in studio_meta.
     */
    #[NoDiscard]
    public static function seedHash(?string $derivedSeed = null): string
    {
        if ($derivedSeed !== null) {
            return hash('sha256', $derivedSeed);
        }

        return hash('sha256', self::CHAIN_SEED_FALLBACK);
    }

    /**
     * Derive a chain seed from the master key via sodium KDF.
     *
     * Call this at boot and store the result in studio_meta('chain_seed').
     * Subsequent calls to seedHash() should pass this derived value.
     *
     * @param string $masterKeyRaw Raw 32-byte master key
     * @return string Hex-encoded derived seed
     *
     * @throws SodiumException
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    #[NoDiscard]
    public static function deriveSeedFromMasterKey(string $masterKeyRaw): string
    {
        $derived = sodium_crypto_kdf_derive_from_key(
            SODIUM_CRYPTO_KDF_KEYBYTES,
            self::CHAIN_SEED_SUB_KEY_ID,
            self::CHAIN_SEED_CONTEXT,
            $masterKeyRaw,
        );

        return bin2hex($derived);
    }

    /**
     * Compute the next chain link for an event.
     *
     * @throws SodiumException
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
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
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
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
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    #[NoDiscard]
    public static function verifyLinkMac(string $currentHash, string $expectedMac, string $chainMacKey, HmacInterface $hmac): bool
    {
        return $hmac->verifyHex($currentHash, $expectedMac, $chainMacKey);
    }

    /**
     * Whether this chain instance can compute/verify MACs.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function hasMacKey(): bool
    {
        return $this->chainMacKey !== null;
    }
}
