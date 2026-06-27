<?php

declare(strict_types=1);

namespace Pulsar\Queue\Driver;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Queue\FailedJob;
use Pulsar\Queue\FailedJobRepositoryInterface;

use function array_values;
use function count;

/**
 * Process-local {@see FailedJobRepositoryInterface}.
 *
 * Holds failed jobs in an in-memory map keyed by job id. Used for tests and for
 * the sync/memory transports. NOT durable across processes — a DB-backed
 * repository must be wired for deployments that require dead-letter retention.
 */
#[Internal]
final class InMemoryFailedJobRepository implements FailedJobRepositoryInterface
{
    /** @var array<string, FailedJob> */
    private array $jobs = [];

    #[Override]
    public function store(FailedJob $job): void
    {
        $this->jobs[$job->id] = $job;
    }

    #[Override]
    public function find(string $id): ?FailedJob
    {
        return $this->jobs[$id] ?? null;
    }

    #[Override]
    public function all(): array
    {
        return array_values($this->jobs);
    }

    #[Override]
    public function forget(string $id): bool
    {
        if (!isset($this->jobs[$id])) {
            return false;
        }

        unset($this->jobs[$id]);

        return true;
    }

    #[Override]
    public function flush(): int
    {
        $count = count($this->jobs);
        $this->jobs = [];

        return $count;
    }

    #[Override]
    public function count(): int
    {
        return count($this->jobs);
    }
}
