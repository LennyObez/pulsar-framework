<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Encryption;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Config\EncryptionConfig;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Crypto\SubKeyId;

use function sodium_crypto_generichash;
use function sodium_memzero;
use function substr;

use const SODIUM_CRYPTO_GENERICHASH_KEYBYTES;
use const SODIUM_CRYPTO_KDF_CONTEXTBYTES;

/**
 * Column encryptor using the framework's crypto subsystem.
 *
 * Uses domain-separated key derivation from the master key for
 * ORM-specific encryption and blind index operations.
 */
#[Internal]
final readonly class AttributeColumnEncryptor implements ColumnEncryptorInterface
{
    private EncryptorInterface $derivedEncryptor;

    public function __construct(
        EncryptorInterface $encryptor,
        private MasterKey $keyProvider,
        private EncryptionConfig $config,
    ) {
        $this->derivedEncryptor = $encryptor->withDerivedKey(
            $keyProvider,
            $this->config->subKeyId,
            $this->config->context,
        );
    }

    #[Override]
    public function encrypt(string $plaintext): string
    {
        return $this->derivedEncryptor->encrypt($plaintext);
    }

    #[Override]
    public function decrypt(string $ciphertext): string
    {
        return $this->derivedEncryptor->decrypt($ciphertext);
    }

    #[Override]
    public function blindIndex(string $plaintext, int $hashLength = 32): string
    {
        // Key the BLAKE2b hash with a SECRET key derived from the master key,
        // never with the public blindIndexContext label. Keying on the label
        // would let anyone holding a dump of an encrypted-plus-blind-indexed
        // PII column brute-force or confirm the plaintext offline, because the
        // key would be public and well known.
        //
        // SubKeyId::OrmBlindIndex separates this key from the encryption key
        // (Orm=5); the label is folded into the 8-byte KDF context (hashed down
        // to length) for additional domain separation, and the derived key is
        // zeroed immediately after use so the readonly instance holds no key.
        $context = substr(
            sodium_crypto_generichash($this->config->blindIndexContext),
            0,
            SODIUM_CRYPTO_KDF_CONTEXTBYTES,
        );

        $key = $this->keyProvider->deriveSubKey(
            SubKeyId::OrmBlindIndex->value,
            $context,
            SODIUM_CRYPTO_GENERICHASH_KEYBYTES,
        );

        try {
            return substr(
                sodium_crypto_generichash($plaintext, $key, $hashLength),
                0,
                $hashLength,
            );
        } finally {
            sodium_memzero($key);
        }
    }
}
