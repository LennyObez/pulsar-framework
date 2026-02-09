<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;
use Random\Engine\Secure;
use Random\Randomizer;

use function hash_equals;
use function hash_hmac;
use function openssl_decrypt;
use function openssl_encrypt;
use function sprintf;
use function strlen;
use function substr;

/**
 * Cipher suite backed by AES-256-GCM via OpenSSL.
 *
 * Provides FIPS 140-2 compliant authenticated encryption with associated data.
 *
 * Ciphertext format: version byte (0x02) || nonce (12) || tag (16) || ciphertext.
 */
#[Api(since: '1.0.0')]
final readonly class AesGcmCipherSuite implements CipherSuiteInterface
{
    private const int VERSION_BYTE = 0x02;
    private const string CIPHER = 'aes-256-gcm';
    private const int KEY_LENGTH = 32;
    private const int NONCE_LENGTH = 12;
    private const int TAG_LENGTH = 16;

    private Randomizer $randomizer;

    public function __construct()
    {
        $this->randomizer = new Randomizer(new Secure());
    }

    public function encrypt(string $plaintext, string $key, string $aad = ''): string
    {
        self::validateKeyLength($key);

        $nonce = $this->randomizer->getBytes(self::NONCE_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw SecurityException::encryptionFailed('AES-256-GCM encryption failed');
        }

        return chr(self::VERSION_BYTE) . $nonce . $tag . $ciphertext;
    }

    public function decrypt(string $ciphertext, string $key, string $aad = ''): string
    {
        self::validateKeyLength($key);

        $headerLength = 1 + self::NONCE_LENGTH + self::TAG_LENGTH;

        if (strlen($ciphertext) < $headerLength) {
            throw SecurityException::decryptionFailed();
        }

        $version = ord($ciphertext[0]);
        if ($version !== self::VERSION_BYTE) {
            throw SecurityException::decryptionFailed();
        }

        $nonce = substr($ciphertext, 1, self::NONCE_LENGTH);
        $tag = substr($ciphertext, 1 + self::NONCE_LENGTH, self::TAG_LENGTH);
        $encrypted = substr($ciphertext, $headerLength);

        $plaintext = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
        );

        if ($plaintext === false) {
            throw SecurityException::decryptionFailed();
        }

        return $plaintext;
    }

    public function hmac(string $data, string $key): string
    {
        return hash_hmac('sha256', $data, $key, true);
    }

    #[NoDiscard]
    public function hmacHex(string $data, string $key): string
    {
        return hash_hmac('sha256', $data, $key, false);
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
        return 'aes-gcm';
    }

    private static function validateKeyLength(string $key): void
    {
        if (strlen($key) !== self::KEY_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('AES-256-GCM requires a %d-byte key, got %d', self::KEY_LENGTH, strlen($key)),
            );
        }
    }
}
