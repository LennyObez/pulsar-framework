<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Encryption;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Orm\Config\EncryptionConfig;
use Pulsar\Extension\Orm\Contracts\ColumnEncryptorInterface;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\KeyProviderInterface;

use function sodium_crypto_generichash;
use function substr;

/**
 * Column encryptor using the framework's crypto subsystem.
 *
 * Uses domain-separated key derivation from the master key for
 * ORM-specific encryption and blind index operations.
 */
#[Internal]
final readonly class AttributeColumnEncryptor implements ColumnEncryptorInterface
{
    private readonly EncryptorInterface $derivedEncryptor;

    public function __construct(
        EncryptorInterface $encryptor,
        KeyProviderInterface $keyProvider,
        private readonly EncryptionConfig $config,
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
        // Use BLAKE2b keyed hash for blind indexing
        // The key is derived from the blind index context
        return substr(
            sodium_crypto_generichash($plaintext, $this->config->blindIndexContext, $hashLength),
            0,
            $hashLength,
        );
    }
}
