<?php

declare(strict_types=1);

namespace Pulsar\Auth\TwoFactor;

use Pulsar\Api\Internal;

use function array_search;

/**
 * In-memory recovery code store for testing and development.
 *
 * Not suitable for production — state is lost on process restart.
 * Bind a database-backed implementation for persistent storage.
 */
#[Internal]
final class InMemoryRecoveryCodeStore implements RecoveryCodeStoreInterface
{
    /** @var array<string, RecoveryCodeSet> */
    private array $sets = [];

    public function loadSet(string $identityId): ?RecoveryCodeSet
    {
        return $this->sets[$identityId] ?? null;
    }

    public function consume(string $identityId, string $codeHash): ConsumeResult
    {
        $set = $this->sets[$identityId] ?? null;

        if ($set === null) {
            return ConsumeResult::failure(ConsumeReason::NotEnrolled);
        }

        $index = array_search($codeHash, $set->codeHashes, true);

        if ($index === false) {
            return ConsumeResult::failure(ConsumeReason::NotFound);
        }

        /** @var int $index */
        if ($set->isUsed($index)) {
            return ConsumeResult::failure(ConsumeReason::AlreadyUsed);
        }

        $this->sets[$identityId] = $set->withUsedIndex($index);

        return ConsumeResult::success($index);
    }

    public function store(string $identityId, RecoveryCodeSet $set): void
    {
        $this->sets[$identityId] = $set;
    }
}
