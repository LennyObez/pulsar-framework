<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Compliance;

use Override;
use Pulsar\Compliance\Evidence\EvidenceChainHead;
use Pulsar\Compliance\Evidence\EvidenceChainHeadAware;
use Pulsar\Compliance\Evidence\EvidenceChainStateAware;
use Pulsar\Compliance\Evidence\EvidenceRecord;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Compliance\Verification\VerificationReport;
use Pulsar\Security\Audit\AuditChainState;

use function array_filter;
use function array_values;
use function count;

/**
 * An evidence store that records evidence of its own while storing a record.
 *
 * A store, or an observer or logger it reaches, that itself asks the chain to
 * record is a nested call on the same stack: the outer append is between reading
 * the chain's height and publishing the new one, so the inner append reads the
 * same height and both records claim the same position. That is precisely what
 * {@see EvidenceChain::verify()} reports as a reordered register, and the outer
 * call cannot make progress until the inner one returns, so no amount of waiting
 * resolves it.
 *
 * {@see $reentered} stops the nesting after one level so a test using this store
 * demonstrates the shape rather than recursing away from it.
 */
final class ReentrantEvidenceRegister implements EvidenceChainStateAware, EvidenceChainHeadAware
{
    /** @var list<EvidenceRecord> */
    public array $records = [];

    public ?EvidenceChainHead $head = null;

    /**
     * The chain to re-enter, or null to behave as an ordinary store.
     *
     * Set after construction because the chain needs the store first.
     */
    public ?EvidenceChain $chain = null;

    /** The report the nested append records. */
    public ?VerificationReport $report = null;

    private bool $reentered = false;

    #[Override]
    public function store(EvidenceRecord $record): void
    {
        $this->records[] = $record;

        if ($this->chain === null || $this->report === null || $this->reentered) {
            return;
        }

        $this->reentered = true;

        $this->chain->record($this->report);
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
        return $this->records;
    }

    #[Override]
    public function get(string $id): ?EvidenceRecord
    {
        foreach ($this->records as $record) {
            if ($record->id === $id) {
                return $record;
            }
        }

        return null;
    }

    #[Override]
    public function countForControl(string $controlId): int
    {
        return count($this->forControl($controlId));
    }

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
