<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\Lock\LockInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\QueueConfig;
use Pulsar\Config\QueueDriverType;
use Pulsar\Container\ContainerInterface;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Queue\Attribute\EffectClassifier;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\Driver\AmqpDriver;
use Pulsar\Queue\Driver\Config\AmqpDriverConfig;
use Pulsar\Queue\Driver\Config\PubSubDriverConfig;
use Pulsar\Queue\Driver\Config\RedisDriverConfig;
use Pulsar\Queue\Driver\Config\SqsDriverConfig;
use Pulsar\Queue\Driver\DatabaseDriver;
use Pulsar\Queue\Driver\DatabaseFailedJobRepository;
use Pulsar\Queue\Driver\InMemoryDriver;
use Pulsar\Queue\Driver\InMemoryFailedJobRepository;
use Pulsar\Queue\Driver\PubSubDriver;
use Pulsar\Queue\Driver\RedisDriver;
use Pulsar\Queue\Driver\SqsDriver;
use Pulsar\Queue\Driver\SyncDriver;
use Pulsar\Queue\Exception\QueueException;
use Pulsar\Queue\FailedJobRepositoryInterface;
use Pulsar\Queue\Middleware\AeadPayloadEncryptor;
use Pulsar\Queue\Middleware\EncryptPayload;
use Pulsar\Queue\Middleware\EnforceEffectClassification;
use Pulsar\Queue\Middleware\MiddlewarePipeline as QueueMiddlewarePipeline;
use Pulsar\Queue\Middleware\PreventDuplicates;
use Pulsar\Queue\Middleware\PropagateContext;
use Pulsar\Queue\Middleware\RateLimit;
use Pulsar\Queue\Monitor\MetricsCollector;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\QueueManager;
use Pulsar\Queue\Retry\QueueRetryPolicy;
use Pulsar\Queue\Serialization\SchemaVersionRegistry;
use Pulsar\Queue\Serialization\TypeRegistry;
use Pulsar\Queue\Worker;
use Pulsar\Queue\WorkerOptions;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\Crypto\MasterKey;
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

        // Exhaustive driver construction -- NO silent fallback. Every durable
        // transport either honours an application-bound driver, builds the real
        // one (the remote drivers validate their extension/SDK in the
        // constructor and connect lazily, so boot stays offline-safe), or fails
        // fast. An operator who configured a durable transport must never
        // silently run on the in-memory driver and lose every job on restart.
        $boundDriver = $container->has(QueueDriverInterface::class)
            ? $container->get(QueueDriverInterface::class)
            : null;
        /** @var QueueDriverInterface|null $boundDriver */

        $driver = match ($queueConfig->driver) {
            QueueDriverType::Sync => new SyncDriver($randomizer),
            QueueDriverType::Memory => new InMemoryDriver($randomizer),
            QueueDriverType::Database => $boundDriver ?? ($container->has(ConnectionManagerInterface::class)
                ? new DatabaseDriver($container->get(ConnectionManagerInterface::class), $randomizer)
                : throw QueueException::driverNotConfigured(
                    'database (requires a database connection; enable the database config)',
                )),
            QueueDriverType::Redis => $boundDriver
                ?? new RedisDriver(RedisDriverConfig::fromArray($queueConfig->driverOptions), $randomizer),
            QueueDriverType::Amqp => $boundDriver
                ?? new AmqpDriver(AmqpDriverConfig::fromArray($queueConfig->driverOptions), $randomizer),
            QueueDriverType::Sqs => $boundDriver
                ?? new SqsDriver(SqsDriverConfig::fromArray($queueConfig->driverOptions), $randomizer),
            QueueDriverType::PubSub => $boundDriver
                ?? new PubSubDriver(PubSubDriverConfig::fromArray($queueConfig->driverOptions), $randomizer),
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

        // Effect classifier (shared by the encryption and enforcement middleware)
        $classifier = new EffectClassifier();
        $container->instance(EffectClassifier::class, $classifier);

        // Payload encryption middleware (config: middleware.encrypt_payloads).
        // EncryptPayload is direction-aware -- it encrypts classified payloads on
        // dispatch and decrypts them on execution -- so the same instance goes
        // into both pipelines. Enabling it without the key material available is
        // a hard error: an operator who believes queue payloads are encrypted
        // at rest must never silently run in plaintext.
        $encryptPayload = null;

        if ($queueConfig->encryptPayloads) {
            if (!$container->has(MasterKey::class)) {
                throw QueueException::middlewareDependencyMissing('encrypt_payloads', MasterKey::class);
            }

            if (!$container->has(KeyRingInterface::class)) {
                throw QueueException::middlewareDependencyMissing('encrypt_payloads', KeyRingInterface::class);
            }

            /** @var MasterKey $masterKey */
            $masterKey = $container->get(MasterKey::class);
            /** @var KeyRingInterface $keyRing */
            $keyRing = $container->get(KeyRingInterface::class);

            $encryptPayload = new EncryptPayload(new AeadPayloadEncryptor($masterKey, $keyRing), $classifier);
        }

        // Dispatch middleware pipeline (context propagation when available)
        $dispatchMiddleware = [];
        $contextHolder = $container->has(RequestContextHolder::class)
            ? $container->get(RequestContextHolder::class)
            : null;

        /** @var RequestContextHolder|null $contextHolder */
        if ($contextHolder !== null) {
            $dispatchMiddleware[] = new PropagateContext($contextHolder);
        }

        if ($encryptPayload !== null) {
            $dispatchMiddleware[] = $encryptPayload;
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

        // Dead-letter repository: prefer an application-provided one, else a
        // durable database-backed store when a connection is available (required
        // for the audit/retention guarantees of regulated domains), else fall
        // back to a process-local in-memory store (sync/memory transports).
        if ($container->has(FailedJobRepositoryInterface::class)) {
            /** @var FailedJobRepositoryInterface $failedJobRepository */
            $failedJobRepository = $container->get(FailedJobRepositoryInterface::class);
        } elseif ($container->has(ConnectionManagerInterface::class)) {
            /** @var ConnectionManagerInterface $connectionManager */
            $connectionManager = $container->get(ConnectionManagerInterface::class);
            $failedJobRepository = new DatabaseFailedJobRepository($connectionManager->connection());
            $container->instance(FailedJobRepositoryInterface::class, $failedJobRepository);
        } else {
            $failedJobRepository = new InMemoryFailedJobRepository();
            $container->instance(FailedJobRepositoryInterface::class, $failedJobRepository);
        }

        // Dead letter queue
        /** @var EventDispatcherInterface|null $eventDispatcher */
        $deadLetterQueue = new DeadLetterQueue(
            $driver,
            $eventDispatcher,
            $queueConfig->deadLetterRegulated,
            $failedJobRepository,
        );
        $container->instance(DeadLetterQueue::class, $deadLetterQueue);

        // Execution middleware pipeline, assembled from config (config/queue.php
        // `middleware` block). Order: duplicate suppression first (cheapest gate),
        // then rate limiting, then effect-classification enforcement, and payload
        // decryption LAST so the plaintext exists for the shortest possible span
        // before the job handler runs. Each middleware enabled without its
        // dependency is a hard boot error (fail-closed), never a silent skip.
        $executionMiddleware = [];

        $lock = $container->has(LockInterface::class) ? $container->get(LockInterface::class) : null;

        if ($queueConfig->preventDuplicates) {
            $executionMiddleware[] = new PreventDuplicates(
                $lock ?? throw QueueException::middlewareDependencyMissing('prevent_duplicates', LockInterface::class),
                $queueConfig->preventDuplicatesTtlSeconds,
            );
        }

        if ($queueConfig->rateLimitEnabled) {
            $executionMiddleware[] = new RateLimit(
                $lock ?? throw QueueException::middlewareDependencyMissing('rate_limit', LockInterface::class),
                $queueConfig->rateLimitTtlSeconds,
                $queueConfig->rateLimitTimeoutMs,
            );
        }

        if ($queueConfig->enforceEffectClassification) {
            $executionMiddleware[] = new EnforceEffectClassification(
                $classifier,
                $eventDispatcher ?? throw QueueException::middlewareDependencyMissing(
                    'enforce_effect_classification',
                    EventDispatcherInterface::class,
                ),
            );
        }

        if ($encryptPayload !== null) {
            $executionMiddleware[] = $encryptPayload;
        }

        $executionPipeline = new QueueMiddlewarePipeline($executionMiddleware);

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
