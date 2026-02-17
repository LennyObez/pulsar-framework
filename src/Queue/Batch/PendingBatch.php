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
    private array $jobs = [];

    /** @var list<Closure(JobBatch): void> */
    private array $thenCallbacks = [];

    /** @var list<Closure(JobBatch): void> */
    private array $catchCallbacks = [];

    /** @var list<Closure(JobBatch): void> */
    private array $finallyCallbacks = [];

    private string $name = '';

    private bool $allowFailures = false;

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

    /**
     * @return list<QueueableInterface>
     */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    /**
     * @return list<Closure(JobBatch): void>
     */
    public function getThenCallbacks(): array
    {
        return $this->thenCallbacks;
    }

    /**
     * @return list<Closure(JobBatch): void>
     */
    public function getCatchCallbacks(): array
    {
        return $this->catchCallbacks;
    }

    /**
     * @return list<Closure(JobBatch): void>
     */
    public function getFinallyCallbacks(): array
    {
        return $this->finallyCallbacks;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function doesAllowFailures(): bool
    {
        return $this->allowFailures;
    }
}
