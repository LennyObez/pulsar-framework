<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Compliance;

use Override;
use Pulsar\Compliance\Evidence\EvidenceChainHead;
use Pulsar\Compliance\Evidence\EvidenceChainHeadAware;
use Pulsar\Compliance\Evidence\EvidenceChainStateAware;
use Pulsar\Compliance\Evidence\EvidenceRecord;
use Pulsar\Security\Audit\AuditChainState;

use function array_filter;
use function array_slice;
use function array_splice;
use function array_values;
use function count;

/**
 * An evidence store whose contents a test can tamper with the way an attacker
 * with write access to the register tampers with a file.
 *
 * {@see \Pulsar\Compliance\Evidence\InMemoryEvidenceStore} cannot serve here: it
 * keys records by id, so it cannot hold the same record twice, cannot hold them
 * out of order, and offers no way to drop one — which is to say it cannot
 * express any of the mutations the verifier exists to detect. This double keeps
 * a plain ordered list and exposes the four operations a tamper needs: remove,
 * replace, reorder, duplicate. The anchor and the corruption flag are settable
 * for the same reason.
 */
final class MutableEvidenceRegister implements EvidenceChainStateAware, EvidenceChainHeadAware
{
    /** @var list<EvidenceRecord> */
    public array $records = [];

    public ?EvidenceChainHead $head = null;

    /** Whether the medium reports a line it could not read. */
    public bool $unreadable = false;

    #[Override]
    public function store(EvidenceRecord $record): void
    {
        $this->records[] = $record;
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
        if ($this->unreadable) {
            return AuditChainState::Corrupted;
        }

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

    /** Drop the record at $index, as deleting a line from the file does. */
    public function remove(int $index): void
    {
        array_splice($this->records, $index, 1);
    }

    /** Keep the first $count records and drop the rest: a tail truncation. */
    public function keepFirst(int $count): void
    {
        $this->records = array_values(array_slice($this->records, 0, $count));
    }

    /** Put $record where $index is, as rewriting a line in place does. */
    public function replace(int $index, EvidenceRecord $record): void
    {
        $records = $this->records;
        $records[$index] = $record;

        $this->records = array_values($records);
    }

    public function swap(int $left, int $right): void
    {
        $records = $this->records;
        [$records[$left], $records[$right]] = [$records[$right], $records[$left]];

        $this->records = array_values($records);
    }

    public function duplicate(int $index): void
    {
        $this->records[] = $this->records[$index];
    }
}
