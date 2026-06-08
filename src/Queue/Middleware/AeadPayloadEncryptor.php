<?php

declare(strict_types=1);

namespace Pulsar\Queue\Middleware;

use Pulsar\Api\Internal;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\Crypto\MasterKey;
use Random\Engine\Secure;
use Random\Randomizer;
use SodiumException;

use function base64_decode;
use function base64_encode;
use function sodium_crypto_aead_xchacha20poly1305_ietf_decrypt;
use function sodium_crypto_aead_xchacha20poly1305_ietf_encrypt;
use function strlen;
use function substr;

/**
 * AEAD payload encryption using XChaCha20-Poly1305 with associated data.
 *
 * AAD composition (pipe-delimited string):
 *   tenant_id | queue_name | job_class_fqcn | schema_version | correlation_id
 *
 * IMPORTANT: The attempt number is intentionally excluded from AAD. Including
 * it would cause decryption failures on retry because the attempt is incremented
 * after dispatch. The payload is encrypted once at dispatch time and must remain
 * decryptable through all retry attempts.
 *
 * AAD rationale: Transport/driver IDs are NOT included because they break
 * legitimate workflows (dev Redis -> prod SQS, driver migrations, failover).
 * Cross-environment replay is prevented by key separation (distinct master
 * keys per environment), not transport binding.
 *
 * Ciphertext format: base64(nonce[24] || ciphertext+tag[N+16])
 * Key management: uses KeyRingInterface for key lookup by kid. Supports key
 * rotation via fallback to previous key on decryption.
 */
#[Internal(reason: 'AEAD encryption is an implementation detail of queue payload security')]
final class AeadPayloadEncryptor
{
    private const int NONCE_LENGTH = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    private const int KEY_LENGTH = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;
    private const int TAG_LENGTH = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    /**
     * Sub-key ID for queue encryption domain separation.
     */
    private const int QUEUE_SUB_KEY_ID = 10;

    /**
     * KDF context for queue encryption (exactly 8 bytes).
     */
    private const string QUEUE_KDF_CONTEXT = 'que_aead';

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly MasterKey $masterKey,
        private readonly KeyRingInterface $keyRing,
    ) {
        $this->randomizer = new Randomizer(new Secure());
    }

    /**
     * Encrypt a payload with AEAD using the given AAD fields.
     *
     * @return array{ciphertext: string, keyId: string} Encrypted payload and the key ID used.
     *
     * @throws QueueException  If encryption fails.
     * @throws SodiumException
     */
    public function encrypt(string $plaintext, string $aad): array
    {
        $key = $this->masterKey->deriveSubKey(self::QUEUE_SUB_KEY_ID, self::QUEUE_KDF_CONTEXT, self::KEY_LENGTH);
        $keyId = $this->masterKey->keyId(self::QUEUE_SUB_KEY_ID, self::QUEUE_KDF_CONTEXT);

        $nonce = $this->randomizer->getBytes(self::NONCE_LENGTH);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);

        return [
            'ciphertext' => base64_encode($nonce . $ciphertext),
            'keyId' => $keyId,
        ];
    }

    /**
     * Decrypt a payload with AEAD, verifying the given AAD fields.
     *
     * Tries the key matching the provided keyId first (via KeyRing), then
     * falls back to the current and previous derived keys for rotation support.
     *
     * @throws QueueException  If decryption fails (tampered AAD, wrong key, etc.).
     * @throws SodiumException
     */
    public function decrypt(string $encoded, string $aad, ?string $keyId): string
    {
        $decoded = base64_decode($encoded, true);

        if ($decoded === false || strlen($decoded) < self::NONCE_LENGTH + self::TAG_LENGTH) {
            throw QueueException::jobFailed('', 'AEAD decryption failed: invalid ciphertext format');
        }

        $nonce = substr($decoded, 0, self::NONCE_LENGTH);
        $ciphertext = substr($decoded, self::NONCE_LENGTH);

        // Try key from KeyRing by kid if provided
        if ($keyId !== null) {
            $ringKey = $this->keyRing->keyFor($keyId);
            if ($ringKey !== null && strlen($ringKey) === self::KEY_LENGTH) {
                $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $ringKey);
                if ($plaintext !== false) {
                    return $plaintext;
                }
            }
        }

        // Fallback: try current derived key
        $currentKey = $this->masterKey->deriveSubKey(self::QUEUE_SUB_KEY_ID, self::QUEUE_KDF_CONTEXT, self::KEY_LENGTH);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $currentKey);
        if ($plaintext !== false) {
            return $plaintext;
        }

        // Fallback: try previous derived key (rotation window)
        if ($this->masterKey->hasPreviousKey()) {
            $previousKey = $this->masterKey->derivePreviousSubKey(
                self::QUEUE_SUB_KEY_ID,
                self::QUEUE_KDF_CONTEXT,
                self::KEY_LENGTH,
            );
            if ($previousKey !== null) {
                $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $previousKey);
                if ($plaintext !== false) {
                    return $plaintext;
                }
            }
        }

        throw QueueException::jobFailed('', 'AEAD decryption failed: authentication tag mismatch (tampered AAD or wrong key)');
    }

    /**
     * Compose the AAD string from envelope fields.
     *
     * Format: tenant_id|queue|job_class|schema_version|correlation_id
     *
     * All fields are joined with pipe delimiter. Null tenant is represented as empty string.
     * The attempt number is intentionally excluded: the payload is encrypted once at
     * dispatch and must remain decryptable through all retry attempts.
     */
    public static function composeAad(
        ?string $tenantId,
        string $queue,
        string $jobClass,
        int $schemaVersion,
        string $correlationId,
    ): string {
        return ($tenantId ?? '') . '|' . $queue . '|' . $jobClass . '|' . $schemaVersion . '|' . $correlationId;
    }
}
