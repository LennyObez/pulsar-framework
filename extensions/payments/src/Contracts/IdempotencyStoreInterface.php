<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Exception\IdempotencyException;
use Pulsar\Extension\Payments\Idempotency\IdempotencyClaim;

/**
 * Atomic claim-based idempotency store contract.
 *
 * @see \Pulsar\Contracts\IdempotencyStoreInterface Planned core-level contract (Plan 3).
 */
#[Api]
interface IdempotencyStoreInterface
{
    /**
     * Atomically claim an idempotency key.
     *
     * Returns IdempotencyClaim indicating:
     * - Replay: key exists with matching parametersHash — return cached payload
     * - Claimed: key is now held by this caller — caller must run provider then commit()
     * - Mismatch: key exists with different parametersHash — throw
     *
     * @throws IdempotencyException On concurrent claim for in-flight key
     */
    public function claim(
        string $key,
        string $parametersHash,
        string $operation,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): IdempotencyClaim;

    /**
     * Commit result payload after successful provider call.
     * Only valid after a Claimed result.
     */
    public function commit(string $key, string $resultPayload): void;

    /**
     * Release a claimed key without committing (on provider failure).
     */
    public function release(string $key): void;

    /**
     * Remove expired records.
     *
     * @return int Number of records pruned
     */
    public function prune(DateTimeImmutable $before): int;
}
