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
use function strlen;

/**
 * Decorator that transparently encrypts/decrypts cache values.
 *
 * Uses libsodium secretbox (XSalsa20-Poly1305) via {@see Encryptor} for
 * authenticated encryption and BLAKE2b HMAC for AAD binding (pool name,
 * key, tenant, purpose). Supports transparent key rotation: values
 * encrypted under the previous master key are re-encrypted on read.
 */
#[Internal]
final class EncryptedCacheDecorator implements CacheDriverInterface
{
    private readonly EncryptorInterface $encryptor;
    private readonly string $hmacKey;
    private readonly string $currentKeyId;
    private readonly ?string $previousKeyId;

    /**
     * @throws SodiumException
     */
    public function __construct(
        private readonly CacheDriverInterface $inner,
        MasterKey $masterKey,
        private readonly string $poolName,
        private readonly string $tenantId = '',
        private readonly string $purpose = 'cache',
    ) {
        $this->encryptor = Encryptor::fromDerivedKey($masterKey, 8, 'app_cenc');
        $this->hmacKey = $masterKey->deriveSubKey(9, 'app_cobs');
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
        $payload = $this->encryptValue($key, $value, $ttlSeconds);

        return $this->inner->set($key, $payload, $ttlSeconds);
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
        return $this->inner->capabilities();
    }

    public function name(): string
    {
        return 'encrypted:' . $this->inner->name();
    }

    private function computeAadHmac(string $key): string
    {
        $aadString = strlen($this->poolName) . ':' . $this->poolName
            . strlen($key) . ':' . $key
            . strlen($this->tenantId) . ':' . $this->tenantId
            . strlen($this->purpose) . ':' . $this->purpose;

        return Hmac::computeHex($aadString, $this->hmacKey);
    }

    private function encryptValue(string $key, string $value, ?int $ttlSeconds = null): string
    {
        $aadHmac = $this->computeAadHmac($key);
        $ciphertext = $this->encryptor->encrypt($value);

        $payload = new CacheEncryptionPayload(
            version: 2,
            keyId: $this->currentKeyId,
            ciphertext: $ciphertext,
            aadHash: $aadHmac,
            ttlSeconds: $ttlSeconds,
        );

        return $payload->toJson();
    }

    private function decryptValue(string $key, string $raw): ?string
    {
        $payload = CacheEncryptionPayload::fromJson($raw);

        if ($payload === null) {
            return null;
        }

        $expectedAadHmac = $this->computeAadHmac($key);

        if (!hash_equals($expectedAadHmac, $payload->aadHash)) {
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

            $reEncrypted = $this->encryptValue($key, $plaintext, $payload->ttlSeconds);
            $this->inner->set($key, $reEncrypted, $payload->ttlSeconds);

            return $plaintext;
        }

        return null;
    }
}
