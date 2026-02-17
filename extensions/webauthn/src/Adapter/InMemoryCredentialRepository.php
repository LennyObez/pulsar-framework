<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Adapter;

use Pulsar\Api\Internal;
use Pulsar\Extension\WebAuthn\Contract\CredentialRepositoryInterface;
use Pulsar\Extension\WebAuthn\PublicKey\CredentialSource;

/**
 * In-memory credential repository for testing and development.
 *
 * Not suitable for production use: credentials are lost on process termination.
 * Production applications should provide a persistent implementation
 * (database-backed) and bind it to CredentialRepositoryInterface.
 */
#[Internal(reason: 'Default in-memory adapter for development')]
final class InMemoryCredentialRepository implements CredentialRepositoryInterface
{
    /** @var array<string, CredentialSource> keyed by credentialId */
    private array $credentials = [];

    public function findById(string $credentialId): ?CredentialSource
    {
        return $this->credentials[$credentialId] ?? null;
    }

    public function findByUserId(string $userId): array
    {
        $result = [];

        foreach ($this->credentials as $credential) {
            if ($credential->userId === $userId) {
                $result[] = $credential;
            }
        }

        return $result;
    }

    public function persist(CredentialSource $credential): void
    {
        $this->credentials[$credential->credentialId] = $credential;
    }

    public function updateCounter(string $credentialId, int $newCounter): void
    {
        $existing = $this->credentials[$credentialId] ?? null;

        if ($existing === null) {
            return;
        }

        $this->credentials[$credentialId] = new CredentialSource(
            credentialId: $existing->credentialId,
            userId: $existing->userId,
            publicKeyPem: $existing->publicKeyPem,
            signatureCounter: $newCounter,
            attestationFormat: $existing->attestationFormat,
            transports: $existing->transports,
            discoverable: $existing->discoverable,
            aaguid: $existing->aaguid,
            createdAt: $existing->createdAt,
        );
    }

    public function remove(string $credentialId): void
    {
        unset($this->credentials[$credentialId]);
    }

    public function removeByUserId(string $userId): void
    {
        foreach ($this->credentials as $id => $credential) {
            if ($credential->userId === $userId) {
                unset($this->credentials[$id]);
            }
        }
    }

    public function exists(string $credentialId): bool
    {
        return isset($this->credentials[$credentialId]);
    }
}
