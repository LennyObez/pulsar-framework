<?php

declare(strict_types=1);

namespace Pulsar\Queue\Batch;

use Closure;
use Pulsar\Api\Api;
use Pulsar\Queue\QueueableInterface;

/**
 * Builder for configuring a job batch before dispatch.
 *
 * Collects jobs and callbacks, then produces a {@see JobBatch} when dispatched.
 * The builder is mutable; the resulting batch is immutable.
 */
#[Api(since: '1.0.0')]
final class PendingBatch
{
    /** @var list<QueueableInterface> */
    public private(set) array $jobs = [];

    /** @var list<Closure(JobBatch): void> */
    public private(set) array $thenCallbacks = [];

    /** @var list<Closure(JobBatch): void> */
    public private(set) array $catchCallbacks = [];

    /** @var list<Closure(JobBatch): void> */
    public private(set) array $finallyCallbacks = [];

    public private(set) string $name = '';

    public private(set) bool $allowFailures = false;

    /**
     * Add a job to the batch.
     *
     * @return $this
     */
    public function add(QueueableInterface $job): self
    {
        $this->jobs[] = $job;

        return $this;
    }

    /**
     * Register a callback to run when the entire batch completes successfully.
     *
     * @param Closure(JobBatch): void $callback
     *
     * @return $this
     */
    public function then(Closure $callback): self
    {
        $this->thenCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback to run when a job in the batch fails.
     *
     * @param Closure(JobBatch): void $callback
     *
     * @return $this
     */
    public function catch(Closure $callback): self
    {
        $this->catchCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback to run when the batch finishes, regardless of outcome.
     *
     * @param Closure(JobBatch): void $callback
     *
     * @return $this
     */
    public function finally(Closure $callback): self
    {
        $this->finallyCallbacks[] = $callback;

        return $this;
    }

    /**
     * Set a descriptive name for the batch.
     *
     * @return $this
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Allow the batch to complete even if individual jobs fail.
     *
     * @return $this
     */
    public function allowFailures(): self
    {
        $this->allowFailures = true;

        return $this;
    }

}
