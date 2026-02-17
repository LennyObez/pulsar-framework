<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Consent;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\OAuth2\Contract\ConsentRepositoryInterface;

use function array_diff;
use function array_values;
use function bin2hex;
use function random_bytes;

/**
 * In-memory consent repository for testing and development.
 */
#[Internal(reason: 'In-memory implementation for testing; not for production use')]
final class InMemoryConsentRepository implements ConsentRepositoryInterface
{
    /** @var array<string, ConsentRecord> Keyed by "{subjectId}:{clientId}" */
    private array $consents = [];

    public function hasConsent(string $subjectId, string $clientId, array $scopes): bool
    {
        $key = $subjectId . ':' . $clientId;
        $record = $this->consents[$key] ?? null;

        if ($record === null) {
            return false;
        }

        // All requested scopes must be covered by existing consent
        $missing = array_diff($scopes, $record->scopes);

        return $missing === [];
    }

    public function grantConsent(string $subjectId, string $clientId, array $scopes): ConsentRecord
    {
        $key = $subjectId . ':' . $clientId;
        $existing = $this->consents[$key] ?? null;

        // Merge with existing scopes if consent already exists
        $allScopes = $scopes;
        if ($existing !== null) {
            $allScopes = array_values(array_unique([...$existing->scopes, ...$scopes]));
        }

        $record = new ConsentRecord(
            id: bin2hex(random_bytes(16)),
            subjectId: $subjectId,
            clientId: $clientId,
            scopes: $allScopes,
            grantedAt: new DateTimeImmutable(),
        );

        $this->consents[$key] = $record;

        return $record;
    }

    public function revokeConsent(string $subjectId, string $clientId): void
    {
        $key = $subjectId . ':' . $clientId;
        unset($this->consents[$key]);
    }

    public function listConsents(string $subjectId): array
    {
        $records = [];

        foreach ($this->consents as $record) {
            if ($record->subjectId === $subjectId) {
                $records[] = $record;
            }
        }

        return $records;
    }
}
