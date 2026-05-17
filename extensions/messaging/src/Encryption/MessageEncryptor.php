<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Encryption;

use Pulsar\Api\Api;
use SodiumException;

use function random_bytes;
use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;
use function strlen;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * Encrypts and decrypts messages using XChaCha20-Poly1305.
 *
 * This is the symmetric cipher used for actual message content.
 * The key is either a direct shared secret (for DMs) or a group
 * symmetric key (distributed via crypto_box to each participant).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MessageEncryptor
{
    /**
     * Encrypt a plaintext message.
     *
     * @param string $plaintext The message content to encrypt
     * @param string $key Symmetric key (32 bytes)
     * @return array{ciphertext: string, nonce: string} Both are raw binary
     *
     * @throws SodiumException
     */
    public function encrypt(string $plaintext, string $key): array
    {
        $this->validateKeyLength($key);

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return [
            'ciphertext' => $ciphertext,
            'nonce' => $nonce,
        ];
    }

    /**
     * Decrypt a ciphertext message.
     *
     * @param string $ciphertext The encrypted message
     * @param string $nonce The nonce used during encryption (24 bytes)
     * @param string $key Symmetric key (32 bytes)
     * @return string|false Plaintext on success, false on authentication failure
     *
     * @throws SodiumException
     */
    public function decrypt(string $ciphertext, string $nonce, string $key): string|false
    {
        $this->validateKeyLength($key);

        return sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
    }

    /**
     * Generate a random symmetric key for message encryption.
     *
     * @return string 32-byte random key
     */
    public function generateKey(): string
    {
        return random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * @throws SodiumException
     */
    private function validateKeyLength(string $key): void
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new SodiumException(
                'Key must be exactly ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes, got ' . strlen($key),
            );
        }
    }
}
