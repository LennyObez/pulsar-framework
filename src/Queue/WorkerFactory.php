<?php

declare(strict_types=1);

namespace Pulsar\Queue;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Queue\Attribute\EffectClassifier;
use Pulsar\Queue\Middleware\MiddlewarePipeline;
use Pulsar\Queue\Monitor\MetricsCollector;
use Pulsar\Queue\Retry\QueueRetryPolicy;
use Pulsar\Queue\Serialization\TypeRegistry;

/**
 * Builds a {@see Worker} with everything the composition root assembled for it.
 *
 * A Worker takes eleven collaborators. `QueueWiring` assembles all of them and
 * binds the result, but `queue:work` cannot use that instance: its options come
 * from command-line flags, and the bound Worker was built with the options from
 * `config/queue.php`. So the command built its own with three collaborators —
 * driver, options, logger — and dropped the other eight on the floor.
 *
 * What a worker started that way loses is not cosmetic. No dead-letter queue, so
 * a job that exhausts its retries is gone rather than parked. No retry policy, no
 * metrics, no events, no request context to correlate its logs with. And no
 * execution pipeline, which is where duplicate suppression, rate limiting, effect
 * classification and — for a regulated deployment, the one that matters —
 * payload decryption live. A worker without the pipeline receives ciphertext and
 * hands it to a handler that expects a job.
 *
 * This factory holds the assembly so that a caller with its own options gets the
 * same worker the container would have handed it, and so that `QueueWiring` and
 * `queue:work` cannot drift apart: the wiring builds its bound Worker through
 * this too.
 *
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WorkerFactory implements WorkerFactoryInterface
{
    public function __construct(
        private QueueDriverInterface $driver,
        private ?LoggerInterface $logger = null,
        private ?RequestContextHolder $contextHolder = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?MetricsCollector $metrics = null,
        private ?EffectClassifier $classifier = null,
        private ?DeadLetterQueue $deadLetterQueue = null,
        private ?TypeRegistry $typeRegistry = null,
        private ?QueueRetryPolicy $retryPolicy = null,
        private MiddlewarePipeline $executionPipeline = new MiddlewarePipeline(),
    ) {}

    #[Override]
    public function create(WorkerOptions $options): Worker
    {
        return new Worker(
            $this->driver,
            $options,
            $this->logger,
            $this->contextHolder,
            $this->eventDispatcher,
            $this->metrics,
            $this->classifier,
            $this->deadLetterQueue,
            $this->typeRegistry,
            $this->retryPolicy,
            $this->executionPipeline,
        );
    }
}
