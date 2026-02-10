<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio;

use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\ConnectionManagerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extension\Studio\Config\StudioConfig;
use Pulsar\Extension\Studio\Console\Aggregation\DashboardAggregator;
use Pulsar\Extension\Studio\Console\Aggregation\TimelineBuilder;
use Pulsar\Extension\Studio\Console\Collector\ExceptionCollector;
use Pulsar\Extension\Studio\Console\Collector\FeatureFlagCollector;
use Pulsar\Extension\Studio\Console\Collector\HttpCollector;
use Pulsar\Extension\Studio\Console\Collector\InstrumentedConnection;
use Pulsar\Extension\Studio\Console\Collector\InstrumentedQueueManager;
use Pulsar\Extension\Studio\Console\Collector\InstrumentedRuntime;
use Pulsar\Extension\Studio\Console\Collector\InstrumentedScheduler;
use Pulsar\Extension\Studio\Console\Collector\InstrumentedWorker;
use Pulsar\Extension\Studio\Console\Collector\LogCollector;
use Pulsar\Extension\Studio\Console\Event\EventFactory;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceExporter;
use Pulsar\Extension\Studio\Console\Evidence\EvidenceVerifier;
use Pulsar\Extension\Studio\Console\Redaction\RedactionPipeline;
use Pulsar\Extension\Studio\Console\Retention\RetentionEnforcer;
use Pulsar\Extension\Studio\Console\Retention\RetentionPolicy;
use Pulsar\Extension\Studio\Console\Storage\BufferedEventStore;
use Pulsar\Extension\Studio\Console\Storage\DatabaseEventStore;
use Pulsar\Extension\Studio\Console\Storage\EncryptedEventStore;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Extension\Studio\Contracts\StudioModuleRegistryInterface;
use Pulsar\Extension\Studio\Internal\StudioModuleRegistry;
use Pulsar\Extension\Studio\Security\StudioAccessGate;
use Pulsar\FeatureFlag\FlagEvaluationLogInterface;
use Pulsar\Http\Middleware\MiddlewarePipelineInterface;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use Pulsar\Observability\ErrorTracking\ErrorAggregatorInterface;
use Pulsar\Observability\Log\Sink\DeferredSinkInterface;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Queue\QueueManager;
use Pulsar\Queue\Worker;
use Pulsar\Routing\RouterInterface;
use Pulsar\Runtime\FpmRuntime;
use Pulsar\Runtime\RuntimeCollectorInterface;
use Pulsar\Scheduler\Scheduler;
use Pulsar\Security\Crypto\EncryptorInterface;
use Pulsar\Security\Crypto\HmacInterface;
use Pulsar\Security\Crypto\KeyProviderInterface;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Tenancy\TenantContext;
use Random\Randomizer;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Studio extension — development console with event collection,
 * evidence chain, and diagnostics UI.
 *
 * Uses lifecycle hooks to integrate without privileged Kernel access:
 * - preBoot: loads config, creates storage, redaction, evidence chain
 * - boot: registers Studio web UI routes
 * - postBoot: wires collectors into final service bindings
 */
final class StudioExtension implements ExtensionInterface, PreBootExtensionInterface, PostBootExtensionInterface
{
    public function name(): string
    {
        return 'pulsar/studio';
    }

    public function register(ContainerInterface $container): void
    {
        // Studio registers RuntimeCollectorInterface so PersistentRuntime
        // can be instrumented without depending on Studio directly.
        // Actual instance is created in preBoot() after config is loaded.
    }

    /**
     * PreBoot: load config, create core Studio services.
     *
     * Faithfully reproduces Kernel::studioPreboot() logic.
     */
    public function preBoot(ContainerInterface $container): void
    {
        /** @var ConfigManagerInterface $configManager */
        $configManager = $container->get(ConfigManagerInterface::class);
        $repository = $configManager->repository();
        $environment = $configManager->environment();

        $configPath = $configManager->configPath();

        if ($configPath === null || !is_file($configPath . DIRECTORY_SEPARATOR . 'studio.php')) {
            return;
        }

        // Load Studio config
        /** @psalm-suppress UnresolvableInclude Studio config path is validated by is_file() above */
        $studioData = require $configPath . DIRECTORY_SEPARATOR . 'studio.php';

        if (!is_array($studioData)) {
            return;
        }

        /** @var array<string, mixed> $studioData */
        $studioConfig = StudioConfig::fromArray($studioData, $environment);
        $container->instance(StudioConfig::class, $studioConfig);

        if (!$studioConfig->enabled) {
            return;
        }

        /** @var AppConfig $appConfig */
        $appConfig = $repository->get(AppConfig::class);

        // Production double-check
        if ($appConfig->mode === EnvironmentMode::Production) {
            $prodConfirm = $environment->get('STUDIO_PRODUCTION_CONFIRM');

            if ($prodConfirm !== 'true') {
                return;
            }
        }

        // Create event store based on configured backend
        /** @var HmacInterface $hmacForStore */
        $hmacForStore = $container->get(HmacInterface::class);

        $metricRegistryForStore = $container->has(MetricRegistry::class)
            ? $container->get(MetricRegistry::class)
            : null;
        /** @var MetricRegistry|null $metricRegistryForStore */

        $baseStore = match ($studioConfig->storeBackend) {
            'database' => $this->createDatabaseStore($container, $hmacForStore),
            'buffered' => $this->createBufferedStore($container, $hmacForStore),
            default => new SqliteEventStore($studioConfig->storagePath, $metricRegistryForStore, $hmacForStore),
        };

        // Register SQLite store for backward compatibility when using sqlite backend
        if ($baseStore instanceof SqliteEventStore) {
            $container->instance(SqliteEventStore::class, $baseStore);
        }
        if ($baseStore instanceof BufferedEventStore) {
            $container->instance(BufferedEventStore::class, $baseStore);
        }
        if ($baseStore instanceof DatabaseEventStore) {
            $container->instance(DatabaseEventStore::class, $baseStore);
        }

        // Optionally wrap with encryption (requires KeyProvider for key derivation)
        $store = $baseStore;
        $isEncrypted = false;
        $hasDecryptionKey = false;
        $chainMacKey = null;
        $archiveMacKey = null;

        if ($baseStore instanceof SqliteEventStore
            && $container->has(KeyProviderInterface::class)
            && $container->has(EncryptorInterface::class)
        ) {
            /** @var MasterKey $masterKey */
            $masterKey = $container->get(KeyProviderInterface::class);
            $hasDecryptionKey = true;

            // Encryption at rest — dedicated subkey 3 (separate from main Encryptor's subkey 1)
            /** @var EncryptorInterface $baseEncryptor */
            $baseEncryptor = $container->get(EncryptorInterface::class);
            $studioEncryptor = $baseEncryptor->withDerivedKey($masterKey, 3, 'stud_enc');
            $encryptedStore = new EncryptedEventStore($baseStore, $studioEncryptor);
            $store = $encryptedStore;
            $isEncrypted = true;
            $container->instance(EncryptedEventStore::class, $encryptedStore);

            // Archive MAC key — subkey 4
            $archiveMacKey = $masterKey->deriveSubKey(4, 'stud_mac');

            // Chain MAC key — subkey 5
            $chainMacKey = $masterKey->deriveSubKey(5, 'stud_chn');
        } elseif ($container->has(KeyProviderInterface::class)) {
            /** @var MasterKey $masterKey */
            $masterKey = $container->get(KeyProviderInterface::class);
            $hasDecryptionKey = true;

            // Archive MAC key — subkey 4
            $archiveMacKey = $masterKey->deriveSubKey(4, 'stud_mac');

            // Chain MAC key — subkey 5
            $chainMacKey = $masterKey->deriveSubKey(5, 'stud_chn');
        }

        $container->instance(EventStoreInterface::class, $store);

        // Redaction pipeline
        $redactionPipeline = RedactionPipeline::withDefaults();
        $container->instance(RedactionPipeline::class, $redactionPipeline);

        // Retention
        $retentionPolicy = new RetentionPolicy(
            maxAgeDays: $studioConfig->retention->maxAgeDays,
            maxSizeMb: $studioConfig->retention->maxSizeMb,
            vacuumIntervalHours: $studioConfig->retention->vacuumIntervalHours,
        );
        $container->instance(RetentionPolicy::class, $retentionPolicy);

        if ($baseStore instanceof SqliteEventStore) {
            $retentionEnforcer = new RetentionEnforcer(
                $baseStore,
                $retentionPolicy,
                $metricRegistryForStore,
            );
            $container->instance(RetentionEnforcer::class, $retentionEnforcer);
        }

        // Context provider (fiber-safe)
        $contextProvider = new FiberScopedContextProvider();
        $container->instance(FiberScopedContextProvider::class, $contextProvider);
        $container->instance(CorrelationContextProviderInterface::class, $contextProvider);

        // Event factory
        /** @var Randomizer $randomizer */
        $randomizer = $container->get(Randomizer::class);
        $eventFactory = EventFactory::create($appConfig->mode->value, $randomizer);
        $container->instance(EventFactory::class, $eventFactory);

        // Tenant context (if available)
        $tenantContext = $container->has(TenantContext::class)
            ? $container->get(TenantContext::class)
            : null;

        /** @var TenantContext|null $tenantContext */

        // Studio manager
        $studioManager = new StudioManager(
            store: $store,
            eventFactory: $eventFactory,
            redactionPipeline: $redactionPipeline,
            tenantContext: $tenantContext,
            chainMacKey: $chainMacKey,
            samplingRate: $studioConfig->samplingRate,
            randomizer: $randomizer,
        );
        $container->instance(StudioManager::class, $studioManager);

        // Aggregation services
        $dashboardAggregator = new DashboardAggregator($store);
        $container->instance(DashboardAggregator::class, $dashboardAggregator);

        $timelineBuilder = new TimelineBuilder($store);
        $container->instance(TimelineBuilder::class, $timelineBuilder);

        // Security gate
        $accessGate = new StudioAccessGate($studioConfig->security, $appConfig->mode);
        $container->instance(StudioAccessGate::class, $accessGate);

        // Module registry
        $moduleRegistry = new StudioModuleRegistry();
        $container->instance(StudioModuleRegistryInterface::class, $moduleRegistry);
        $container->instance(StudioModuleRegistry::class, $moduleRegistry);

        // Evidence services
        /** @var HmacInterface $hmacForEvidence */
        $hmacForEvidence = $container->get(HmacInterface::class);

        $evidenceVerifier = new EvidenceVerifier($hmacForEvidence);
        $container->instance(EvidenceVerifier::class, $evidenceVerifier);

        $evidenceExporter = new EvidenceExporter(
            store: $store,
            hmac: $hmacForEvidence,
            archiveMacKey: $archiveMacKey,
            isEncrypted: $isEncrypted,
            hasDecryptionKey: $hasDecryptionKey,
        );
        $container->instance(EvidenceExporter::class, $evidenceExporter);

        // Register RuntimeCollectorInterface for PersistentRuntime
        if ($container->has(MetricRegistry::class)) {
            /** @var MetricRegistry $metricRegistry */
            $metricRegistry = $container->get(MetricRegistry::class);

            $collector = new InstrumentedRuntime(
                inner: new FpmRuntime($container->get(KernelInterface::class)),
                contextProvider: $contextProvider,
                metricRegistry: $metricRegistry,
                emit: $studioManager->emitCallback(),
            );
            $container->instance(RuntimeCollectorInterface::class, $collector);
            $container->instance(InstrumentedRuntime::class, $collector);
        }
    }

    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // Studio web UI routes are registered by StudioServer if enabled
    }

    /**
     * PostBoot: wire collectors into final service bindings.
     *
     * Faithfully reproduces Kernel::attachStudioCollectors() logic.
     * Runs after all extensions have booted, ensuring collectors wrap
     * the final post-boot state of decorated services.
     */
    public function postBoot(ContainerInterface $container): void
    {
        if (!$container->has(StudioManager::class)) {
            return;
        }

        /** @var StudioManager $studioManager */
        $studioManager = $container->get(StudioManager::class);
        $emit = $studioManager->emitCallback();

        /** @var StudioConfig $studioConfig */
        $studioConfig = $container->get(StudioConfig::class);
        $collectorConfig = $studioConfig->collectors;

        /** @var FiberScopedContextProvider $contextProvider */
        $contextProvider = $container->get(FiberScopedContextProvider::class);

        /** @var AppConfig $appConfig */
        $appConfig = $container->get(AppConfig::class);

        /** @var Randomizer $randomizer */
        $randomizer = $container->get(Randomizer::class);

        // 1. HTTP collector (global middleware — piped last = innermost)
        if ($collectorConfig->http && $container->has(MiddlewarePipelineInterface::class)) {
            $httpCollector = new HttpCollector($contextProvider, $emit, $randomizer);
            $container->instance(HttpCollector::class, $httpCollector);

            /** @var MiddlewarePipelineInterface $pipeline */
            $pipeline = $container->get(MiddlewarePipelineInterface::class);
            $pipeline->pipe($httpCollector);
        }

        // 2. Database collector (decorator)
        if ($collectorConfig->database && $container->has(ConnectionManagerInterface::class)) {
            /** @var ConnectionManagerInterface $connectionManager */
            $connectionManager = $container->get(ConnectionManagerInterface::class);
            $connection = $connectionManager->connection();

            $instrumentedConnection = new InstrumentedConnection(
                inner: $connection,
                contextProvider: $contextProvider,
                emit: $emit,
                storeRawSql: $collectorConfig->storeRawSql,
                environmentMode: $appConfig->mode,
            );
            $container->instance(InstrumentedConnection::class, $instrumentedConnection);
            $container->instance(ConnectionInterface::class, $instrumentedConnection);
        }

        // 3. Log collector (via DeferredSink)
        if ($collectorConfig->logs && $container->has(DeferredSinkInterface::class)) {
            $logCollector = new LogCollector($contextProvider, $emit);
            $container->instance(LogCollector::class, $logCollector);

            /** @var DeferredSinkInterface $deferredSink */
            $deferredSink = $container->get(DeferredSinkInterface::class);
            $deferredSink->addSink($logCollector);
        }

        // 4. Exception collector (observer on ErrorAggregator)
        if ($collectorConfig->exceptions && $container->has(ErrorAggregatorInterface::class)) {
            $exceptionCollector = new ExceptionCollector($contextProvider, $emit);
            $container->instance(ExceptionCollector::class, $exceptionCollector);

            /** @var ErrorAggregatorInterface $aggregator */
            $aggregator = $container->get(ErrorAggregatorInterface::class);
            $aggregator->addObserver($exceptionCollector->handleError(...));
        }

        // 5. Scheduler collector (decorator)
        if ($collectorConfig->scheduler && $container->has(Scheduler::class)) {
            /** @var Scheduler $scheduler */
            $scheduler = $container->get(Scheduler::class);

            $instrumentedScheduler = new InstrumentedScheduler($scheduler, $contextProvider, $emit, $randomizer);
            $container->instance(InstrumentedScheduler::class, $instrumentedScheduler);
        }

        // 6. Feature flag collector (observer on FlagEvaluationLog)
        if ($collectorConfig->featureFlags && $container->has(FlagEvaluationLogInterface::class)) {
            $featureFlagCollector = new FeatureFlagCollector($contextProvider, $emit);
            $container->instance(FeatureFlagCollector::class, $featureFlagCollector);

            /** @var FlagEvaluationLogInterface $evaluationLog */
            $evaluationLog = $container->get(FlagEvaluationLogInterface::class);
            $evaluationLog->addObserver($featureFlagCollector->handleEvaluation(...));
        }

        // 7. Queue collector (decorator on QueueManager)
        if ($collectorConfig->queue && $container->has(QueueManager::class)) {
            /** @var QueueManager $queueManager */
            $queueManager = $container->get(QueueManager::class);

            $instrumentedQueueManager = new InstrumentedQueueManager($queueManager, $contextProvider, $emit);
            $container->instance(InstrumentedQueueManager::class, $instrumentedQueueManager);

            // Worker decorator (if worker is registered)
            if ($container->has(Worker::class)) {
                /** @var Worker $worker */
                $worker = $container->get(Worker::class);

                $instrumentedWorker = new InstrumentedWorker($worker, $contextProvider, $emit, $randomizer);
                $container->instance(InstrumentedWorker::class, $instrumentedWorker);
            }
        }
    }

    public function providers(): array
    {
        return [];
    }

    private function createDatabaseStore(ContainerInterface $container, HmacInterface $hmac): DatabaseEventStore
    {
        /** @var ConnectionManagerInterface $connectionManager */
        $connectionManager = $container->get(ConnectionManagerInterface::class);

        return new DatabaseEventStore($connectionManager->connection(), hmac: $hmac);
    }

    private function createBufferedStore(ContainerInterface $container, HmacInterface $hmac): BufferedEventStore
    {
        $databaseStore = $this->createDatabaseStore($container, $hmac);
        $container->instance(DatabaseEventStore::class, $databaseStore);

        return new BufferedEventStore($databaseStore);
    }
}
