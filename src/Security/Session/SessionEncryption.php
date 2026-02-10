<?php

declare(strict_types=1);

namespace Pulsar\Security\Session;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;
use Random\Engine\Secure;
use Random\Randomizer;
use SensitiveParameter;
use SodiumException;

use function base64_decode;
use function base64_encode;
use function sodium_crypto_aead_xchacha20poly1305_ietf_decrypt;
use function sodium_crypto_aead_xchacha20poly1305_ietf_encrypt;
use function sodium_memzero;
use function strlen;
use function substr;

/**
 * Session payload encryption using AEAD (XChaCha20-Poly1305) with Additional Authenticated Data.
 *
 * AAD binds the ciphertext to the session context (session ID, handler type, domain),
 * preventing payload transplant attacks between sessions or handlers.
 *
 * Key rotation is supported via key_id in the ciphertext header. On read, the key_id
 * is used to look up the correct decryption key from the KeyRing. On write, the
 * current key is always used.
 */
#[Internal]
final class SessionEncryption
{
    private const int SUB_KEY_ID = 3;

    private const string KDF_CONTEXT = 'session_';

    private const int NONCE_LENGTH = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    private const int KID_BINARY_LENGTH = 8;

    private readonly Randomizer $randomizer;

    /**
     * @param string $currentKey Raw key bytes for encryption
     */
    private function __construct(
        private readonly KeyRingInterface $keyRing,
        private readonly string $currentKid,
        #[SensitiveParameter]
        private string $currentKey,
    ) {
        $this->randomizer = new Randomizer(new Secure());
    }

    public function __destruct()
    {
        $key = $this->currentKey;
        $this->currentKey = '';

        try {
            sodium_memzero($key);
        } catch (SodiumException) {
            // Best-effort zeroing
        }
    }

    /**
     * @return array<string, string>
     * @throws SecurityException
     */
    public function __serialize(): array
    {
        throw SecurityException::serializationForbidden('SessionEncryption');
    }

    /**
     * @param array<string, mixed> $data
     * @throws SecurityException
     */
    public function __unserialize(array $data): void
    {
        throw SecurityException::serializationForbidden('SessionEncryption');
    }

    /**
     * Create from MasterKey, deriving a session-specific encryption key.
     *
     * @throws SodiumException
     */
    #[NoDiscard]
    public static function fromMasterKey(MasterKey $masterKey): self
    {
        $keyRing = EnvKeyRing::fromMasterKey($masterKey, self::SUB_KEY_ID, self::KDF_CONTEXT);
        $currentKey = $masterKey->deriveSubKey(self::SUB_KEY_ID, self::KDF_CONTEXT);
        $currentKid = $masterKey->keyId(self::SUB_KEY_ID, self::KDF_CONTEXT);

        return new self($keyRing, $currentKid, $currentKey);
    }

    /**
     * Encrypt session data with AEAD + AAD.
     *
     * Output format: base64(kid_binary(8 bytes) || nonce(24 bytes) || ciphertext+tag)
     * AAD = session_id|handler_type|domain
     *
     * @throws SecurityException If encryption fails
     */
    public function encrypt(
        string $data,
        string $sessionId,
        string $handlerType,
        string $domain,
    ): string {
        $aad = $this->buildAad($sessionId, $handlerType, $domain);
        $nonce = $this->randomizer->getBytes(self::NONCE_LENGTH);
        $kidBinary = hex2bin($this->currentKid) ?: '';

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $data,
                $aad,
                $nonce,
                $this->currentKey,
            );
        } catch (SodiumException $e) {
            throw SecurityException::sessionEncryptionFailed($e->getMessage());
        }

        return base64_encode($kidBinary . $nonce . $ciphertext);
    }

    /**
     * Decrypt session data, verifying AEAD + AAD integrity.
     *
     * Tries the key identified by the embedded key_id. If the key_id matches the
     * current key, decrypts directly. Otherwise, falls back to the KeyRing for
     * previous-key lookup during rotation windows.
     *
     * @throws SecurityException If decryption fails (wrong key, tampered data, etc.)
     */
    public function decrypt(
        string $encrypted,
        string $sessionId,
        string $handlerType,
        string $domain,
    ): string {
        $decoded = base64_decode($encrypted, true);

        if ($decoded === false) {
            throw SecurityException::sessionEncryptionFailed('invalid base64 encoding');
        }

        $minLength = self::KID_BINARY_LENGTH + self::NONCE_LENGTH + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

        if (strlen($decoded) < $minLength) {
            throw SecurityException::sessionEncryptionFailed('ciphertext too short');
        }

        $kidBinary = substr($decoded, 0, self::KID_BINARY_LENGTH);
        $kid = bin2hex($kidBinary);
        $nonce = substr($decoded, self::KID_BINARY_LENGTH, self::NONCE_LENGTH);
        $ciphertext = substr($decoded, self::KID_BINARY_LENGTH + self::NONCE_LENGTH);

        $aad = $this->buildAad($sessionId, $handlerType, $domain);

        $key = $this->resolveKey($kid);

        if ($key === null) {
            throw SecurityException::sessionEncryptionFailed('unknown key identifier');
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                $aad,
                $nonce,
                $key,
            );
        } catch (SodiumException $e) {
            throw SecurityException::sessionEncryptionFailed($e->getMessage());
        }

        if ($plaintext === false) {
            throw SecurityException::sessionEncryptionFailed('ciphertext is invalid or has been tampered with');
        }

        return $plaintext;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'currentKid' => $this->currentKid,
            'currentKey' => '[REDACTED]',
        ];
    }

    private function buildAad(string $sessionId, string $handlerType, string $domain): string
    {
        return $sessionId . '|' . $handlerType . '|' . $domain;
    }

    private function resolveKey(string $kid): ?string
    {
        if ($kid === $this->currentKid) {
            return $this->currentKey;
        }

        return $this->keyRing->keyFor($kid);
    }
}
