<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Adapter;

use Pulsar\Api\Internal;
use Pulsar\Extension\WebAuthn\Authenticator\AuthenticatorRecord;
use Pulsar\Extension\WebAuthn\Contract\AuthenticatorRepositoryInterface;

/**
 * In-memory authenticator repository for testing and development.
 *
 * Not suitable for production use — records are lost on process termination.
 * Production applications should provide a persistent implementation
 * (database-backed) and bind it to AuthenticatorRepositoryInterface.
 */
#[Internal(reason: 'Default in-memory adapter for development')]
final class InMemoryAuthenticatorRepository implements AuthenticatorRepositoryInterface
{
    /** @var array<string, AuthenticatorRecord> keyed by credentialId */
    private array $records = [];

    public function findByCredentialId(string $credentialId): ?AuthenticatorRecord
    {
        return $this->records[$credentialId] ?? null;
    }

    public function listByUserId(string $userId): array
    {
        $result = [];

        foreach ($this->records as $record) {
            if ($record->userId === $userId) {
                $result[] = $record;
            }
        }

        return $result;
    }

    public function register(AuthenticatorRecord $record): void
    {
        $this->records[$record->credentialId] = $record;
    }

    public function rename(string $credentialId, string $newName): void
    {
        $existing = $this->records[$credentialId] ?? null;

        if ($existing === null) {
            return;
        }

        $this->records[$credentialId] = new AuthenticatorRecord(
            credentialId: $existing->credentialId,
            userId: $existing->userId,
            displayName: $newName,
            type: $existing->type,
            aaguid: $existing->aaguid,
            active: $existing->active,
            registeredAt: $existing->registeredAt,
            lastUsedAt: $existing->lastUsedAt,
        );
    }

    public function revoke(string $credentialId): void
    {
        $existing = $this->records[$credentialId] ?? null;

        if ($existing === null) {
            return;
        }

        $this->records[$credentialId] = new AuthenticatorRecord(
            credentialId: $existing->credentialId,
            userId: $existing->userId,
            displayName: $existing->displayName,
            type: $existing->type,
            aaguid: $existing->aaguid,
            active: false,
            registeredAt: $existing->registeredAt,
            lastUsedAt: $existing->lastUsedAt,
        );
    }

    public function countActive(string $userId): int
    {
        $count = 0;

        foreach ($this->records as $record) {
            if ($record->userId === $userId && $record->active) {
                $count++;
            }
        }

        return $count;
    }
}
