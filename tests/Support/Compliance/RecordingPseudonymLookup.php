<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support\Compliance;

use Override;
use Pulsar\Security\Compliance\Pseudonymization\InMemoryPseudonymLookup;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymLookupInterface;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymMapping;

use function in_array;

/**
 * A pseudonym table that behaves exactly like the in-memory one and remembers
 * which subjects were stored and which were deleted.
 *
 * It exists because {@see PseudonymLookupInterface} offers no enumeration, and
 * deliberately so: it is a re-identification table, and a contract that could
 * list every subject it holds would be a worse thing to have in a process than
 * the mappings themselves.
 *
 * That leaves a test with no supported way to ask "did the measurement leave
 * anything behind?", and the unsupported way — reading the stub's private array
 * with Reflection — is a piercing construct this repository counts and reviews.
 * Recording the calls answers the same question from outside, and answers it
 * more precisely: `delete()` having been called on the subject `store()` created
 * is what "the erasure reached the table" actually means.
 */
final class RecordingPseudonymLookup implements PseudonymLookupInterface
{
    /** @var list<string> Subjects passed to store(), in order. */
    public array $stored = [];

    /** @var list<string> Subjects passed to delete(), in order. */
    public array $deleted = [];

    private InMemoryPseudonymLookup $mappings;

    public function __construct()
    {
        $this->mappings = new InMemoryPseudonymLookup();
    }

    #[Override]
    public function store(string $subjectId, string $pseudonym, string $encryptedSalt): void
    {
        $this->stored[] = $subjectId;
        $this->mappings->store($subjectId, $pseudonym, $encryptedSalt);
    }

    #[Override]
    public function findBySubjectId(string $subjectId): ?PseudonymMapping
    {
        return $this->mappings->findBySubjectId($subjectId);
    }

    #[Override]
    public function findByPseudonym(string $pseudonym): ?PseudonymMapping
    {
        return $this->mappings->findByPseudonym($pseudonym);
    }

    #[Override]
    public function delete(string $subjectId): bool
    {
        $this->deleted[] = $subjectId;

        return $this->mappings->delete($subjectId);
    }

    /**
     * The subjects that were stored and never deleted.
     *
     * @return list<string>
     */
    public function surviving(): array
    {
        $surviving = [];

        foreach ($this->stored as $subjectId) {
            if (!in_array($subjectId, $this->deleted, true)) {
                $surviving[] = $subjectId;
            }
        }

        return $surviving;
    }
}
