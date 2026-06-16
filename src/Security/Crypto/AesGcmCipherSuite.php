<?php

declare(strict_types=1);

namespace Pulsar\Security\Crypto;

use InvalidArgumentException;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\Exception\SecurityException;
use Random\Engine\Secure;
use Random\Randomizer;

use function chr;
use function hash_equals;
use function hash_hmac;
use function openssl_decrypt;
use function openssl_encrypt;
use function ord;
use function sodium_crypto_aead_aes256gcm_decrypt;
use function sodium_crypto_aead_aes256gcm_encrypt;
use function sodium_crypto_aead_aes256gcm_is_available;
use function sprintf;
use function strlen;
use function substr;

/**
 * Cipher suite backed by AES-256-GCM.
 *
 * Primary path uses libsodium's `sodium_crypto_aead_aes256gcm_*` which calls
 * hardware AES-NI when available (Intel Westmere 2010+, ARMv8 Crypto
 * Extensions). This keeps the crypto stack within ADR-0006's libsodium-only
 * policy.
 *
 * Fallback path uses OpenSSL `aes-256-gcm`. It is selected only when
 * `sodium_crypto_aead_aes256gcm_is_available()` returns `false`, which
 * indicates an unsupported CPU (e.g. AMD pre-Bulldozer, ARMv7 without Crypto
 * Ext, some emulated environments). The fallback is documented as a narrowly
 * scoped exception in ADR-0006.
 *
 * FIPS 140-2/140-3 compliance notes:
 *  - AES-256-GCM is FIPS-approved regardless of implementation.
 *  - When FIPS compliance is required, deploy with a NIST-validated
 *    OpenSSL FIPS provider and set `PULSAR_CRYPTO_FORCE_OPENSSL=1` so the
 *    fallback path is taken unconditionally.
 *  - Use `FipsValidator::verify()` to confirm deployment.
 *
 * Ciphertext format: version byte (0x02) || nonce (12) || tag (16) || ciphertext.
 * The tag is 16 bytes, placed before the ciphertext so the libsodium API
 * (which concatenates ciphertext || tag) can be normalised without branching
 * at the format boundary.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AesGcmCipherSuite implements CipherSuiteInterface
{
    private const int VERSION_BYTE = 0x02;
    private const string OPENSSL_CIPHER = 'aes-256-gcm';
    private const int KEY_LENGTH = 32;
    private const int NONCE_LENGTH = 12;
    private const int TAG_LENGTH = 16;

    private Randomizer $randomizer;
    private bool $useSodium;

    /**
     * @param bool|null $preferSodium Overrides the default auto-detection.
     *                                `true` forces sodium (throws at construction
     *                                if sodium AES-GCM is unavailable),
     *                                `false` forces OpenSSL, `null` auto-detects.
     */
    public function __construct(?bool $preferSodium = null)
    {
        $this->randomizer = new Randomizer(new Secure());

        if ($preferSodium === true) {
            if (!sodium_crypto_aead_aes256gcm_is_available()) {
                throw SecurityException::encryptionFailed(
                    'sodium_crypto_aead_aes256gcm is unavailable on this CPU; '
                    . 'omit $preferSodium or set it to false to fall back to OpenSSL.',
                );
            }
            $this->useSodium = true;
        } elseif ($preferSodium === false) {
            $this->useSodium = false;
        } else {
            $this->useSodium = sodium_crypto_aead_aes256gcm_is_available();
        }
    }

    public function encrypt(string $plaintext, string $key, string $aad = ''): string
    {
        self::validateKeyLength($key);

        $nonce = $this->randomizer->getBytes(self::NONCE_LENGTH);

        if ($this->useSodium) {
            // libsodium returns ciphertext || tag concatenated
            $combined = sodium_crypto_aead_aes256gcm_encrypt($plaintext, $aad, $nonce, $key);
            $tagOffset = strlen($combined) - self::TAG_LENGTH;
            $ciphertext = substr($combined, 0, $tagOffset);
            $tag = substr($combined, $tagOffset);

            return chr(self::VERSION_BYTE) . $nonce . $tag . $ciphertext;
        }

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::OPENSSL_CIPHER,
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

        if ($this->useSodium) {
            // libsodium expects ciphertext || tag concatenated
            $plaintext = sodium_crypto_aead_aes256gcm_decrypt(
                $encrypted . $tag,
                $aad,
                $nonce,
                $key,
            );

            if ($plaintext === false) {
                throw SecurityException::decryptionFailed();
            }

            return $plaintext;
        }

        $plaintext = openssl_decrypt(
            $encrypted,
            self::OPENSSL_CIPHER,
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
        return hash_hmac('sha256', $data, $key);
    }

    #[NoDiscard]
    public function verifyHmac(string $data, string $expected, string $key): bool
    {
        $computed = $this->hmac($data, $key);

        return hash_equals($expected, $computed);
    }

    /**
     * Whether libsodium's hardware-accelerated AES-256-GCM is available on
     * this CPU. Hosts lacking AES-NI (AMD pre-Bulldozer, ARMv7 without Crypto
     * Extensions, some emulated VMs) will report `false`; the cipher suite
     * silently falls back to OpenSSL in that case.
     */
    #[NoDiscard]
    public static function isSodiumAesAvailable(): bool
    {
        return sodium_crypto_aead_aes256gcm_is_available();
    }

    /**
     * Check if the OpenSSL build has FIPS mode enabled. Relevant when the
     * cipher suite is forced onto the OpenSSL path for FIPS 140-2/140-3
     * validated provider use.
     */
    #[NoDiscard]
    public static function isFipsAvailable(): bool
    {
        return FipsValidator::isFipsAvailable();
    }

    /**
     * Whether this instance is currently using the libsodium path. False
     * indicates the OpenSSL fallback is active (either auto-detected or
     * forced via `$preferSodium = false`).
     */
    #[NoDiscard]
    public function isUsingSodium(): bool
    {
        return $this->useSodium;
    }

    #[NoDiscard]
    public function name(): string
    {
        // Stable suite identifier per CipherSuiteInterface (e.g. 'sodium',
        // 'aes-gcm'); the sodium/openssl backend is an internal detail and must
        // not change the identity used for payload routing.
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
