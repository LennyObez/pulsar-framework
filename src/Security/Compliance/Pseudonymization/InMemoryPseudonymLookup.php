<?php

declare(strict_types=1);

namespace Pulsar\Security\Compliance\Pseudonymization;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Pulsar\Api\Internal;

/**
 * In-memory pseudonym lookup for testing and development.
 *
 * Maintains an array-backed store indexed by subject identifier with
 * a secondary index by pseudonym for efficient reverse lookups.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Test/dev pseudonym lookup implementation')]
final class InMemoryPseudonymLookup implements PseudonymLookupInterface
{
    /** @var array<string, PseudonymMapping> Indexed by subjectId */
    private array $bySubjectId = [];

    /** @var array<string, string> Maps pseudonym -> subjectId */
    private array $pseudonymIndex = [];

    #[Override]
    public function store(string $subjectId, string $pseudonym, string $encryptedSalt): void
    {
        $mapping = new PseudonymMapping(
            subjectId: $subjectId,
            pseudonym: $pseudonym,
            encryptedSalt: $encryptedSalt,
            createdAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $this->bySubjectId[$subjectId] = $mapping;
        $this->pseudonymIndex[$pseudonym] = $subjectId;
    }

    #[Override]
    public function findBySubjectId(string $subjectId): ?PseudonymMapping
    {
        return $this->bySubjectId[$subjectId] ?? null;
    }

    #[Override]
    public function findByPseudonym(string $pseudonym): ?PseudonymMapping
    {
        $subjectId = $this->pseudonymIndex[$pseudonym] ?? null;

        if ($subjectId === null) {
            return null;
        }

        return $this->bySubjectId[$subjectId] ?? null;
    }

    #[Override]
    public function delete(string $subjectId): bool
    {
        $mapping = $this->bySubjectId[$subjectId] ?? null;

        if ($mapping === null) {
            return false;
        }

        unset($this->pseudonymIndex[$mapping->pseudonym]);
        unset($this->bySubjectId[$subjectId]);

        return true;
    }
}
