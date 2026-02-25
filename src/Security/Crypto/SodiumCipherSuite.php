<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;
use Random\Engine\Secure;
use Random\Randomizer;
use SodiumException;

use function hash_equals;
use function sodium_bin2hex;
use function sodium_crypto_aead_xchacha20poly1305_ietf_decrypt;
use function sodium_crypto_aead_xchacha20poly1305_ietf_encrypt;
use function sodium_crypto_generichash;
use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;
use function strlen;
use function substr;

/**
 * Cipher suite backed by libsodium primitives.
 *
 * - Without AAD: XSalsa20-Poly1305 via sodium_crypto_secretbox (24-byte nonce).
 * - With AAD: XChaCha20-Poly1305-IETF via sodium_crypto_aead_xchacha20poly1305 (24-byte nonce).
 * - HMAC: keyed BLAKE2b via sodium_crypto_generichash (32-byte output).
 *
 * Ciphertext format: version byte (0x01) || nonce || ciphertext+mac.
 */
#[Api(since: '1.0.0')]
final readonly class SodiumCipherSuite implements CipherSuiteInterface
{
    private const int VERSION_BYTE = 0x01;
    private const int SECRETBOX_NONCE_LENGTH = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    private const int AEAD_NONCE_LENGTH = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    private const int HASH_LENGTH = SODIUM_CRYPTO_GENERICHASH_BYTES;

    private Randomizer $randomizer;

    public function __construct()
    {
        $this->randomizer = new Randomizer(new Secure());
    }

    public function encrypt(string $plaintext, string $key, string $aad = ''): string
    {
        try {
            if ($aad !== '') {
                return $this->encryptAead($plaintext, $key, $aad);
            }

            return $this->encryptSecretbox($plaintext, $key);
        } catch (SodiumException $e) {
            throw SecurityException::encryptionFailed($e->getMessage());
        }
    }

    public function decrypt(string $ciphertext, string $key, string $aad = ''): string
    {
        if (strlen($ciphertext) < 1) {
            throw SecurityException::decryptionFailed();
        }

        $version = ord($ciphertext[0]);
        if ($version !== self::VERSION_BYTE) {
            throw SecurityException::decryptionFailed();
        }

        try {
            if ($aad !== '') {
                return $this->decryptAead($ciphertext, $key, $aad);
            }

            return $this->decryptSecretbox($ciphertext, $key);
        } catch (SodiumException) {
            throw SecurityException::decryptionFailed();
        }
    }

    public function hmac(string $data, string $key): string
    {
        try {
            return sodium_crypto_generichash($data, $key, self::HASH_LENGTH);
        } catch (SodiumException $e) {
            throw SecurityException::encryptionFailed('HMAC computation failed: ' . $e->getMessage());
        }
    }

    #[NoDiscard]
    public function hmacHex(string $data, string $key): string
    {
        try {
            $raw = sodium_crypto_generichash($data, $key, self::HASH_LENGTH);

            return sodium_bin2hex($raw);
        } catch (SodiumException $e) {
            throw SecurityException::encryptionFailed('HMAC computation failed: ' . $e->getMessage());
        }
    }

    #[NoDiscard]
    public function verifyHmac(string $data, string $expected, string $key): bool
    {
        $computed = $this->hmac($data, $key);

        return hash_equals($expected, $computed);
    }

    #[NoDiscard]
    public function name(): string
    {
        return 'sodium';
    }

    private function encryptSecretbox(string $plaintext, string $key): string
    {
        $nonce = $this->randomizer->getBytes(self::SECRETBOX_NONCE_LENGTH);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return chr(self::VERSION_BYTE) . $nonce . $ciphertext;
    }

    private function decryptSecretbox(string $ciphertext, string $key): string
    {
        $minLength = 1 + self::SECRETBOX_NONCE_LENGTH + SODIUM_CRYPTO_SECRETBOX_MACBYTES;

        if (strlen($ciphertext) < $minLength) {
            throw SecurityException::decryptionFailed();
        }

        $nonce = substr($ciphertext, 1, self::SECRETBOX_NONCE_LENGTH);
        $encrypted = substr($ciphertext, 1 + self::SECRETBOX_NONCE_LENGTH);

        $plaintext = sodium_crypto_secretbox_open($encrypted, $nonce, $key);

        if ($plaintext === false) {
            throw SecurityException::decryptionFailed();
        }

        return $plaintext;
    }

    private function encryptAead(string $plaintext, string $key, string $aad): string
    {
        $nonce = $this->randomizer->getBytes(self::AEAD_NONCE_LENGTH);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);

        return chr(self::VERSION_BYTE) . $nonce . $ciphertext;
    }

    private function decryptAead(string $ciphertext, string $key, string $aad): string
    {
        $minLength = 1 + self::AEAD_NONCE_LENGTH + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

        if (strlen($ciphertext) < $minLength) {
            throw SecurityException::decryptionFailed();
        }

        $nonce = substr($ciphertext, 1, self::AEAD_NONCE_LENGTH);
        $encrypted = substr($ciphertext, 1 + self::AEAD_NONCE_LENGTH);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($encrypted, $aad, $nonce, $key);

        if ($plaintext === false) {
            throw SecurityException::decryptionFailed();
        }

        return $plaintext;
    }
}
