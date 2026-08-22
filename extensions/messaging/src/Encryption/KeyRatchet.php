<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Encryption;

use Pulsar\Api\Api;
use SodiumException;

use function sodium_crypto_generichash;
use function sodium_memzero;

use const SODIUM_CRYPTO_GENERICHASH_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

/**
 * Provides forward secrecy via symmetric key ratcheting.
 *
 * Each session uses ephemeral keys derived from the shared secret.
 * After each ratchet step, the previous key material is destroyed,
 * ensuring that compromise of a future key cannot decrypt past messages.
 *
 * Uses BLAKE2b for key derivation in the ratchet chain.
 * @api
 */
#[Api(since: '1.0.0')]
final class KeyRatchet
{
    private string $chainKey;
    private int $counter = 0;

    /**
     * @param string $initialSecret Initial shared secret (32 bytes) from key exchange
     */
    public function __construct(string $initialSecret)
    {
        $this->chainKey = sodium_crypto_generichash(
            'pulsar-ratchet-init',
            $initialSecret,
            SODIUM_CRYPTO_GENERICHASH_KEYBYTES,
        );
    }

    /**
     * Derive the next message key and advance the ratchet.
     *
     * The chain key is updated so previous keys cannot be recovered.
     *
     * @return array{messageKey: string, counter: int}
     *
     * @throws SodiumException
     */
    public function advance(): array
    {
        // Derive message key from chain key
        $messageKey = sodium_crypto_generichash(
            'pulsar-msg-key-' . $this->counter,
            $this->chainKey,
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
        );

        // Advance chain key (forward secrecy: old chain key is destroyed)
        $oldChainKey = $this->chainKey;
        $this->chainKey = sodium_crypto_generichash(
            'pulsar-chain-advance',
            $this->chainKey,
            SODIUM_CRYPTO_GENERICHASH_KEYBYTES,
        );
        sodium_memzero($oldChainKey);

        $counter = $this->counter;
        $this->counter++;

        return [
            'messageKey' => $messageKey,
            'counter' => $counter,
        ];
    }

    /**
     * Get the current ratchet step counter.
     */
    public function counter(): int
    {
        return $this->counter;
    }

    /**
     * Reset the ratchet with a new shared secret (e.g., after re-keying).
     *
     * @throws SodiumException
     */
    public function reset(string $newSecret): void
    {
        $oldKey = $this->chainKey;
        sodium_memzero($oldKey);

        $this->chainKey = sodium_crypto_generichash(
            'pulsar-ratchet-init',
            $newSecret,
            SODIUM_CRYPTO_GENERICHASH_KEYBYTES,
        );

        $this->counter = 0;
    }

    /**
     * Securely destroy the ratchet state.
     */
    public function destroy(): void
    {
        $oldKey = $this->chainKey;
        sodium_memzero($oldKey);
        $this->chainKey = '';
        $this->counter = 0;
    }
}
