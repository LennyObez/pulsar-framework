<?php

declare(strict_types=1);

namespace Pulsar\Idempotency;

use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Pulsar\Api\Api;
use Pulsar\Idempotency\Exception\IdempotencyException;

use function sprintf;

/**
 * In-memory idempotency store with Fiber-safe mutex.
 *
 * Uses an internal in-flight map to prevent interleaved Fiber execution
 * from double-processing the same key.
 * @api
 */
#[Api(since: '1.0.0')]
final class InMemoryIdempotencyStore implements IdempotencyStoreInterface
{
    /** @var array<string, IdempotencyRecord> */
    private array $records = [];

    /** @var array<string, bool> Fiber-safe in-flight mutex */
    private array $inFlight = [];

    #[Override]
    public function claim(
        string $key,
        string $parametersHash,
        string $operation,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): IdempotencyClaim {
        // A non-positive TTL yields expiresAt <= createdAt, so the record
        // is born already expired and idempotency protection silently
        // vanishes. Reject it as a caller contract violation rather than
        // store a record that can never replay.
        if ($ttlSeconds <= 0) {
            throw new InvalidArgumentException(
                sprintf('Idempotency TTL must be a positive number of seconds, got %d.', $ttlSeconds),
            );
        }

        // Check for concurrent in-flight claim
        if (isset($this->inFlight[$key])) {
            throw IdempotencyException::concurrentClaim($key);
        }

        // Check for existing record
        if (isset($this->records[$key])) {
            $record = $this->records[$key];

            // Expired records are treated as nonexistent
            if ($record->expiresAt <= $now) {
                unset($this->records[$key]);
            } else {
                // Check parameter hash match
                if ($record->parametersHash !== $parametersHash) {
                    return IdempotencyClaim::mismatch();
                }

                // Replay; return cached result if committed
                if ($record->resultPayload !== null) {
                    return IdempotencyClaim::replay($record->resultPayload);
                }

                // Record exists but not committed: concurrent processing
                throw IdempotencyException::concurrentClaim($key);
            }
        }

        // Claim the key
        $this->inFlight[$key] = true;
        $this->records[$key] = new IdempotencyRecord(
            key: $key,
            parametersHash: $parametersHash,
            operation: $operation,
            createdAt: $now,
            expiresAt: $now->modify('+' . $ttlSeconds . ' seconds'),
        );

        return IdempotencyClaim::claimed();
    }

    #[Override]
    public function commit(string $key, string $resultPayload): void
    {
        if (isset($this->records[$key])) {
            $record = $this->records[$key];
            $this->records[$key] = new IdempotencyRecord(
                key: $record->key,
                parametersHash: $record->parametersHash,
                operation: $record->operation,
                createdAt: $record->createdAt,
                expiresAt: $record->expiresAt,
                resultPayload: $resultPayload,
            );
        }

        unset($this->inFlight[$key]);
    }

    #[Override]
    public function release(string $key): void
    {
        unset($this->inFlight[$key], $this->records[$key]);
    }

    #[Override]
    public function prune(DateTimeImmutable $before): int
    {
        $pruned = 0;

        foreach ($this->records as $key => $record) {
            if ($record->expiresAt <= $before) {
                // Clear the in-flight mutex alongside the record. A record
                // pruned while still claimed (very short TTL, or a $before
                // ahead of the claim window) would otherwise orphan its
                // $inFlight entry, causing the next claim() to throw a
                // spurious ConcurrentClaim and lock the key until restart.
                unset($this->records[$key], $this->inFlight[$key]);
                $pruned++;
            }
        }

        return $pruned;
    }
}
