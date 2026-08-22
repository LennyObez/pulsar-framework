<?php

declare(strict_types=1);

namespace Pulsar\Cache\Application\Encryption;

use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Driver\CacheDriverCapabilities;
use Pulsar\Cache\Application\Driver\CacheDriverInterface;
use Pulsar\Cache\Application\Exception\UnsupportedCapabilityException;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Exception\SecurityException;
use SodiumException;

use function hash_equals;
use function max;
use function strlen;
use function time;

/**
 * Decorator that transparently encrypts/decrypts cache values.
 *
 * Uses libsodium secretbox (XSalsa20-Poly1305) via {@see Encryptor} for
 * authenticated encryption and BLAKE2b HMAC for AAD binding (pool name,
 * key, tenant, purpose). Supports transparent key rotation: values
 * encrypted under the previous master key are re-encrypted on read.
 */
#[Internal]
final readonly class EncryptedCacheDecorator implements CacheDriverInterface
{
    private EncryptorInterface $encryptor;
    private string $hmacKey;
    private ?string $previousHmacKey;
    private string $currentKeyId;
    private ?string $previousKeyId;

    /**
     * @throws SodiumException
     */
    public function __construct(
        private CacheDriverInterface $inner,
        MasterKey $masterKey,
        private string $poolName,
        private string $tenantId = '',
        private string $purpose = 'cache',
    ) {
        $this->encryptor = Encryptor::fromDerivedKey($masterKey, 8, 'app_cenc');
        $this->hmacKey = $masterKey->deriveSubKey(9, 'app_cobs');
        // The AAD MAC key rotates with the master key, so an entry written under
        // the previous key carries an AAD the current key cannot reproduce. Keep
        // the previous MAC key too, otherwise the AAD check rejects every
        // previous-key entry before the re-encrypt-on-read path can run.
        $this->previousHmacKey = $masterKey->derivePreviousSubKey(9, 'app_cobs');
        $this->currentKeyId = $masterKey->keyId(8, 'app_cenc');
        $this->previousKeyId = $masterKey->previousKeyId(8, 'app_cenc');
    }

    public function get(string $key): ?string
    {
        $raw = $this->inner->get($key);

        if ($raw === null) {
            return null;
        }

        return $this->decryptValue($key, $raw);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string|null>
     */
    public function getMultiple(array $keys): array
    {
        $results = [];

        foreach ($keys as $k) {
            $results[$k] = $this->get($k);
        }

        return $results;
    }

    public function set(string $key, string $value, ?int $ttlSeconds): bool
    {
        // Stamp the payload with an absolute expiry so a later re-encrypt on key
        // rotation can preserve it instead of restarting the TTL clock.
        $expiresAt = $ttlSeconds !== null ? time() + $ttlSeconds : null;
        $payload = $this->encryptValue($key, $value, $expiresAt);

        return $this->inner->set($key, $payload, $ttlSeconds);
    }

    public function add(string $key, string $value, ?int $ttlSeconds): bool
    {
        // Encrypt then delegate to the inner add, preserving its atomicity: the
        // conditional store still happens once, on the encrypted payload.
        $expiresAt = $ttlSeconds !== null ? time() + $ttlSeconds : null;
        $payload = $this->encryptValue($key, $value, $expiresAt);

        return $this->inner->add($key, $payload, $ttlSeconds);
    }

    /**
     * @param array<string, string> $values
     */
    public function setMultiple(array $values, ?int $ttlSeconds): bool
    {
        $success = true;

        foreach ($values as $k => $v) {
            if (!$this->set($k, $v, $ttlSeconds)) {
                $success = false;
            }
        }

        return $success;
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    /**
     * @param list<string> $keys
     */
    public function deleteMultiple(array $keys): bool
    {
        return $this->inner->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function increment(string $key, int $step = 1): int|false
    {
        throw UnsupportedCapabilityException::atomicIncrementOnEncryptedPool();
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        throw UnsupportedCapabilityException::atomicIncrementOnEncryptedPool();
    }

    public function capabilities(): CacheDriverCapabilities
    {
        $inner = $this->inner->capabilities();

        // Authenticated encryption is incompatible with server-side atomic
        // arithmetic (the backend cannot increment ciphertext), so this
        // decorator's increment()/decrement() throw. Forwarding the inner
        // driver's counter support would make tags_strategy 'auto' pick
        // StrictTagStrategy, whose tag-version bumps call increment() — an
        // exception on the first invalidation of an encrypted tagged pool.
        // Mask both counter-dependent capabilities so 'auto' degrades to the
        // best-effort strategy and explicit 'strict' fails loudly at build
        // time instead.
        return new CacheDriverCapabilities(
            supportsTagsStrict: false,
            supportsLocksFencing: $inner->supportsLocksFencing,
            supportsBinary: $inner->supportsBinary,
            supportsAtomicIncrement: false,
        );
    }

    public function name(): string
    {
        return 'encrypted:' . $this->inner->name();
    }

    private function computeAadHmac(string $key, string $hmacKey): string
    {
        $aadString = strlen($this->poolName) . ':' . $this->poolName
            . strlen($key) . ':' . $key
            . strlen($this->tenantId) . ':' . $this->tenantId
            . strlen($this->purpose) . ':' . $this->purpose;

        return Hmac::computeHex($aadString, $hmacKey);
    }

    private function encryptValue(string $key, string $value, ?int $expiresAt = null): string
    {
        $aadHmac = $this->computeAadHmac($key, $this->hmacKey);
        $ciphertext = $this->encryptor->encrypt($value);

        $payload = new CacheEncryptionPayload(
            version: 2,
            keyId: $this->currentKeyId,
            ciphertext: $ciphertext,
            aadHash: $aadHmac,
            expiresAt: $expiresAt,
        );

        return $payload->toJson();
    }

    private function decryptValue(string $key, string $raw): ?string
    {
        $payload = CacheEncryptionPayload::fromJson($raw);

        if ($payload === null) {
            return null;
        }

        // Accept an AAD produced by either the current or the previous MAC key:
        // a previous-key entry was written when the previous key was current, so
        // only the previous MAC key reproduces its AAD. Both keys are tried so a
        // rotated entry survives the integrity check and reaches re-encryption.
        $aadValid = hash_equals($this->computeAadHmac($key, $this->hmacKey), $payload->aadHash)
            || ($this->previousHmacKey !== null
                && hash_equals($this->computeAadHmac($key, $this->previousHmacKey), $payload->aadHash));

        if (!$aadValid) {
            return null;
        }

        if ($payload->keyId === $this->currentKeyId) {
            try {
                return $this->encryptor->decrypt($payload->ciphertext);
            } catch (SecurityException | SodiumException) {
                return null;
            }
        }

        if ($this->previousKeyId !== null && $payload->keyId === $this->previousKeyId) {
            try {
                $plaintext = $this->encryptor->decrypt($payload->ciphertext);
            } catch (SecurityException | SodiumException) {
                return null;
            }

            // Preserve the original absolute expiry: re-encrypt with the same
            // expiresAt and hand the inner driver only the time that remains, so
            // a rotating read cannot extend the entry's lifetime.
            $remainingTtl = $payload->expiresAt !== null
                ? max(0, $payload->expiresAt - time())
                : null;
            $reEncrypted = $this->encryptValue($key, $plaintext, $payload->expiresAt);
            $this->inner->set($key, $reEncrypted, $remainingTtl);

            return $plaintext;
        }

        return null;
    }
}
