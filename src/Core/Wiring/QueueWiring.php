<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\QueueDriverType;
use Pulsar\Container\ContainerInterface;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Queue\Attribute\EffectClassifier;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\Driver\InMemoryDriver;
use Pulsar\Queue\Driver\SyncDriver;
use Pulsar\Queue\Middleware\MiddlewarePipeline as QueueMiddlewarePipeline;
use Pulsar\Queue\Middleware\PropagateContext;
use Pulsar\Queue\Monitor\MetricsCollector;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\QueueManager;
use Pulsar\Queue\Retry\QueueRetryPolicy;
use Pulsar\Queue\Serialization\SchemaVersionRegistry;
use Pulsar\Queue\Serialization\TypeRegistry;
use Pulsar\Queue\Worker;
use Pulsar\Queue\WorkerOptions;
use Pulsar\Routing\Router;
use Random\Randomizer;

#[Internal]
final readonly class QueueWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(QueueConfig::class)) {
            return;
        }

        /** @var QueueConfig $queueConfig */
        $queueConfig = $repository->get(QueueConfig::class);
        $container->instance(QueueConfig::class, $queueConfig);

        if (!$queueConfig->enabled) {
            return;
        }

        // Queue driver
        /** @var Randomizer $randomizer */
        $randomizer = $container->get(Randomizer::class);

        $driver = match ($queueConfig->driver) {
            QueueDriverType::Sync => new SyncDriver($randomizer),
            QueueDriverType::Memory => new InMemoryDriver($randomizer),
            default => $container->has(QueueDriverInterface::class)
                ? $container->get(QueueDriverInterface::class)
                : new InMemoryDriver(),
        };

        if (!$container->has(QueueDriverInterface::class)) {
            $container->instance(QueueDriverInterface::class, $driver);
        }

        // Optional services
        $eventDispatcher = $container->has(EventDispatcherInterface::class)
            ? $container->get(EventDispatcherInterface::class)
            : null;

        $typeRegistry = $container->has(TypeRegistry::class)
            ? $container->get(TypeRegistry::class)
            : null;

        $schemaVersionRegistry = $container->has(SchemaVersionRegistry::class)
            ? $container->get(SchemaVersionRegistry::class)
            : null;

        // Metrics collector
        $metrics = new MetricsCollector($driver);
        $container->instance(MetricsCollector::class, $metrics);

        // Dispatch middleware pipeline (context propagation when available)
        $dispatchMiddleware = [];
        $contextHolder = $container->has(RequestContextHolder::class)
            ? $container->get(RequestContextHolder::class)
            : null;

        /** @var RequestContextHolder|null $contextHolder */
        if ($contextHolder !== null) {
            $dispatchMiddleware[] = new PropagateContext($contextHolder);
        }

        $dispatchPipeline = new QueueMiddlewarePipeline($dispatchMiddleware);

        // Queue manager
        /** @var EventDispatcherInterface|null $eventDispatcher */
        /** @var TypeRegistry|null $typeRegistry */
        /** @var SchemaVersionRegistry|null $schemaVersionRegistry */
        $queueManager = new QueueManager(
            $queueConfig,
            $driver,
            $eventDispatcher,
            $metrics,
            $typeRegistry,
            $schemaVersionRegistry,
            $dispatchPipeline,
        );
        $container->instance(QueueManager::class, $queueManager);

        // Worker options
        $workerOptions = WorkerOptions::fromConfig($queueConfig);
        $container->instance(WorkerOptions::class, $workerOptions);

        // Retry policy
        $retryPolicy = QueueRetryPolicy::fromConfig($queueConfig);
        $container->instance(QueueRetryPolicy::class, $retryPolicy);

        // Effect classifier
        $classifier = new EffectClassifier();
        $container->instance(EffectClassifier::class, $classifier);

        // Dead letter queue
        /** @var EventDispatcherInterface|null $eventDispatcher */
        $deadLetterQueue = new DeadLetterQueue($driver, $eventDispatcher);
        $container->instance(DeadLetterQueue::class, $deadLetterQueue);

        // Execution middleware pipeline (empty by default; encryption/dedup/rate-limit
        // are added by application-level configuration or extension wirings)
        $executionPipeline = new QueueMiddlewarePipeline();

        // Worker
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        /** @var LoggerInterface|null $logger */
        $worker = new Worker(
            $driver,
            $workerOptions,
            $logger,
            $contextHolder,
            $eventDispatcher,
            $metrics,
            $classifier,
            $deadLetterQueue,
            $typeRegistry,
            $retryPolicy,
            $executionPipeline,
        );
        $container->instance(Worker::class, $worker);
    }
}
