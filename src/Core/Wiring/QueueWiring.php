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
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\Driver\InMemoryDriver;
use Pulsar\Queue\Driver\SyncDriver;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\QueueManager;
use Pulsar\Queue\Retry\QueueRetryPolicy;
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
            QueueDriverType::Database => $container->has(QueueDriverInterface::class)
                ? $container->get(QueueDriverInterface::class)
                : new InMemoryDriver(),
        };

        if (!$container->has(QueueDriverInterface::class)) {
            $container->instance(QueueDriverInterface::class, $driver);
        }

        // Queue manager (with context propagation)
        $contextHolder = $container->has(RequestContextHolder::class)
            ? $container->get(RequestContextHolder::class)
            : null;

        /** @var RequestContextHolder|null $contextHolder */
        $queueManager = new QueueManager($queueConfig, $driver, $contextHolder);
        $container->instance(QueueManager::class, $queueManager);

        // Worker options
        $workerOptions = WorkerOptions::fromConfig($queueConfig);
        $container->instance(WorkerOptions::class, $workerOptions);

        // Worker
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        /** @var LoggerInterface|null $logger */
        $worker = new Worker($driver, $workerOptions, $logger, $contextHolder);
        $container->instance(Worker::class, $worker);

        // Retry policy
        $retryPolicy = QueueRetryPolicy::fromConfig($queueConfig);
        $container->instance(QueueRetryPolicy::class, $retryPolicy);

        // Dead letter queue
        $deadLetterQueue = new DeadLetterQueue($driver);
        $container->instance(DeadLetterQueue::class, $deadLetterQueue);
    }
}
