<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\EncryptorInterface;
use SensitiveParameter;

/**
 * In-memory TOTP secret store for testing and development.
 *
 * Encrypts secrets using the provided Encryptor (AEAD, XSalsa20-Poly1305).
 * Not suitable for production — state is lost on process restart.
 * Bind a database-backed implementation for persistent storage.
 */
#[Internal]
final class InMemoryTotpSecretStore implements TotpSecretStoreInterface
{
    /** @var array<string, string> identityId => encrypted secret */
    private array $secrets = [];

    public function __construct(
        private readonly ?EncryptorInterface $encryptor = null,
    ) {}

    public function store(string $identityId, #[SensitiveParameter] string $secret): void
    {
        $this->secrets[$identityId] = $this->encryptor !== null
            ? $this->encryptor->encrypt($secret)
            : $secret;
    }

    public function retrieve(string $identityId): ?string
    {
        if (!isset($this->secrets[$identityId])) {
            return null;
        }

        return $this->encryptor !== null
            ? $this->encryptor->decrypt($this->secrets[$identityId])
            : $this->secrets[$identityId];
    }

    public function delete(string $identityId): void
    {
        unset($this->secrets[$identityId]);
    }
}
