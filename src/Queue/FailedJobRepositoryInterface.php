<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Pulsar\Api\Api;

/**
 * Persistence port for dead-letter (failed) jobs.
 *
 * {@see DeadLetterQueue} delegates all storage to an implementation of this
 * port so that dead-lettered jobs — and their retry / inspect / delete / purge
 * state — survive worker restarts. A process-local implementation
 * ({@see Driver\InMemoryFailedJobRepository}) is the default for tests and
 * memory/sync transports; a durable implementation
 * ({@see Driver\DatabaseFailedJobRepository}) is wired automatically when a
 * database connection is available, which is required for the audit/retention
 * guarantees of regulated domains.
 * @api
 */
#[Api(since: '1.0.0')]
interface FailedJobRepositoryInterface
{
    /**
     * Persist a failed job. Storing the same id again overwrites the prior
     * record (idempotent upsert), so a redelivered poison job does not create
     * duplicate dead-letter entries.
     */
    public function store(FailedJob $job): void;

    /**
     * Retrieve a failed job by id, or null if none is stored.
     */
    public function find(string $id): ?FailedJob;

    /**
     * All stored failed jobs.
     *
     * @return list<FailedJob>
     */
    public function all(): array;

    /**
     * Remove a failed job by id. Returns true if a record was removed, false if
     * the id was not present.
     */
    public function forget(string $id): bool;

    /**
     * Remove every stored failed job. Returns the number removed.
     */
    public function flush(): int;

    /**
     * Number of failed jobs currently stored.
     */
    public function count(): int;
}
