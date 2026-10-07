<?php

declare(strict_types=1);

namespace Pulsar\Idempotency;

use DateTimeImmutable;
use InvalidArgumentException;
use Pulsar\Api\Api;
use Pulsar\Idempotency\Exception\IdempotencyException;

/**
 * Atomic claim-based idempotency store contract.
 * @api
 */
#[Api(since: '1.0.0')]
interface IdempotencyStoreInterface
{
    /**
     * Atomically claim an idempotency key.
     *
     * Returns IdempotencyClaim indicating:
     * - Replay: key exists with matching parametersHash; return cached payload
     * - Claimed: key is now held by this caller; caller must run provider then commit()
     * - Mismatch: key exists with different parametersHash; returns an
     *   IdempotencyClaim with status Mismatch. The caller must inspect
     *   $claim->status and throw IdempotencyException::parameterMismatch()
     *   itself if a hard failure is desired — claim() does not throw on
     *   mismatch.
     *
     * $ttlSeconds must be a positive number of seconds; a non-positive
     * value is a caller contract violation.
     *
     * @throws IdempotencyException On concurrent claim for in-flight key
     * @throws InvalidArgumentException If $ttlSeconds is not positive
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
