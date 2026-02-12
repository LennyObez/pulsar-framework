<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use Override;
use Pulsar\Api\Api;

use function array_filter;
use function array_values;
use function count;

/**
 * In-memory evidence store for testing and development.
 *
 * Production deployments should use a persistent store (database-backed).
 */
#[Api(since: '1.0.0')]
final class InMemoryEvidenceStore implements EvidenceStoreInterface
{
    /** @var array<string, EvidenceRecord> */
    private array $records = [];

    #[Override]
    public function store(EvidenceRecord $record): void
    {
        $this->records[$record->id] = $record;
    }

    #[Override]
    public function forControl(string $controlId): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(EvidenceRecord $r): bool => $r->controlId === $controlId,
        ));
    }

    #[Override]
    public function all(): array
    {
        return array_values($this->records);
    }

    #[Override]
    public function get(string $id): ?EvidenceRecord
    {
        return $this->records[$id] ?? null;
    }

    #[Override]
    public function countForControl(string $controlId): int
    {
        return count($this->forControl($controlId));
    }
}
