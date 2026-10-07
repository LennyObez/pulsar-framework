<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Evidence;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\EventEnvelope;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\KeyProviderInterface;
use SodiumException;

use function hash;

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
     * Derive a chain seed from the master key through the key-provider seam.
     *
     * Call this at boot and store the result in studio_meta('chain_seed').
     * Subsequent calls to seedHash() should pass this derived value.
     *
     * Takes a {@see KeyProviderInterface} rather than the raw 32 bytes, and that
     * is the whole point of the parameter type. The previous signature was
     * `deriveSeedFromMasterKey(string $masterKeyRaw)`: it obliged every caller to
     * unwrap the master key into a plain string and called
     * `sodium_crypto_kdf_derive_from_key()` itself, so the root key travelled
     * through a variable that {@see \Pulsar\Security\Crypto\MasterKey}'s
     * destructor never sees and cannot zero, and the derivation could not be
     * redirected to an HSM- or KMS-backed provider. Nothing about the derived
     * bytes changes — same sub-key id, same context, same 32-byte length, same
     * hex encoding — so seeds already stored in `studio_meta` stay valid.
     *
     * `tests/Unit/Security/Crypto/KdfSeamTest.php` keeps the primitive inside the
     * crypto module, so this cannot quietly revert to a direct call.
     *
     * @param KeyProviderInterface $keyProvider The seam that holds and scrubs the master key
     *
     * @return string Hex-encoded derived seed
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function deriveSeedFromMasterKey(KeyProviderInterface $keyProvider): string
    {
        return $keyProvider->deriveSubKeyHex(
            self::CHAIN_SEED_SUB_KEY_ID,
            self::CHAIN_SEED_CONTEXT,
            SODIUM_CRYPTO_KDF_KEYBYTES,
        );
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
