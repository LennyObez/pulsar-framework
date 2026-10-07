<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use Override;
use Pulsar\Api\Api;
use Pulsar\Security\Audit\AuditChainState;

use function array_filter;
use function array_values;
use function count;

/**
 * In-memory evidence store for testing and development.
 *
 * Production deployments should use a persistent store (database-backed).
 * @api
 */
#[Api(since: '1.0.0')]
final class InMemoryEvidenceStore implements EvidenceChainStateAware, EvidenceChainHeadAware
{
    /** @var array<string, EvidenceRecord> */
    private array $records = [];

    /**
     * The chain's anchor, held for the life of the process like everything else
     * here.
     *
     * Kept rather than declined so that a chain over this store behaves the way it
     * behaves over {@see FileEvidenceStore} — the same verdicts for the same
     * register — instead of reporting {@see \Pulsar\Compliance\Verification\EvidenceChainVerdict::Unanchored}
     * on every test and every development run. Records and anchor live in the same
     * object and are written in the same call, so they cannot disagree; what this
     * store cannot do is survive the process, and that is stated on
     * {@see chainState()} rather than dressed up as an anchoring problem.
     */
    private ?EvidenceChainHead $head = null;

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

    /**
     * Empty or healthy, never corrupted: nothing is serialised, so there is no
     * medium on which a record can be truncated or edited.
     *
     * The state this store cannot report is the one that matters — it holds
     * records only for the lifetime of the process that wrote them, so a chain
     * over it restarts at genesis every time no matter how carefully the chain
     * resumes. That is not corruption and must not be reported as it; it is the
     * reason a deployment keeping a durable security record gets
     * {@see FileEvidenceStore} instead.
     */
    #[Override]
    public function chainState(): AuditChainState
    {
        return $this->records === [] ? AuditChainState::Empty : AuditChainState::Healthy;
    }

    #[Override]
    public function hasHead(): bool
    {
        return $this->head !== null;
    }

    #[Override]
    public function head(): ?EvidenceChainHead
    {
        return $this->head;
    }

    #[Override]
    public function writeHead(EvidenceChainHead $head): void
    {
        $this->head = $head;
    }
}
