<?php

declare(strict_types=1);

namespace Pulsar\Queue\Chain;

use Pulsar\Api\Api;
use Pulsar\Queue\QueueableInterface;

use function array_map;
use function count;

/**
 * A sequential chain of jobs to be executed in order.
 *
 * Jobs are processed one at a time in sequence. If any job fails,
 * the remaining jobs in the chain are moved to the dead-letter queue
 * and a {@see \Pulsar\Queue\Event\ChainFailed} event is emitted.
 */
#[Api(since: '1.0.0')]
final readonly class JobChain
{
    /**
     * @param string                    $id   Unique chain identifier.
     * @param list<QueueableInterface>  $jobs Ordered list of jobs to execute.
     */
    public function __construct(
        public string $id,
        public array $jobs,
    ) {}

    /**
     * The total number of jobs in the chain.
     */
    public function count(): int
    {
        return count($this->jobs);
    }

    /**
     * Get the job at a specific index in the chain.
     */
    public function jobAt(int $index): ?QueueableInterface
    {
        return $this->jobs[$index] ?? null;
    }

    /**
     * The fully-qualified class names of all jobs in the chain.
     *
     * @return list<string>
     */
    public function jobClasses(): array
    {
        return array_map(static fn(QueueableInterface $job): string => $job::class, $this->jobs);
    }
}
