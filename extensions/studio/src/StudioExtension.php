<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio;

use function is_array;
use function is_file;

use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionManagerInterface;
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
use Pulsar\Extension\Studio\Console\Storage\EncryptedEventStore;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Console\Storage\SqliteEventStore;
use Pulsar\Extension\Studio\Security\StudioAccessGate;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\FeatureFlag\FlagEvaluationLog;
use Pulsar\Http\Middleware\MiddlewarePipelineInterface;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use Pulsar\Observability\ErrorTracking\ErrorAggregator;
use Pulsar\Observability\Log\Sink\DeferredSink;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Queue\QueueManager;
use Pulsar\Queue\Worker;
use Pulsar\Routing\RouterInterface;
use Pulsar\Runtime\FpmRuntime;
use Pulsar\Runtime\RuntimeCollectorInterface;
use Pulsar\Scheduler\Scheduler;
use Pulsar\Security\Crypto\Encryptor;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Tenancy\TenantContext;
use Random\Randomizer;

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
        /** @var ConfigManager $configManager */
        $configManager = $container->get(ConfigManager::class);
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

        // Create SQLite event store
        $sqliteStore = new SqliteEventStore(
            $studioConfig->storagePath,
            $container->has(MetricRegistry::class)
                ? $container->get(MetricRegistry::class)
                : null,
        );
        /** @var MetricRegistry|null $_ Psalm hint */
        $container->instance(SqliteEventStore::class, $sqliteStore);

        // Optionally wrap with encryption (requires MasterKey for key derivation)
        $store = $sqliteStore;
        $isEncrypted = false;
        $hasDecryptionKey = false;
        $chainMacKey = null;
        $archiveMacKey = null;

        if ($container->has(MasterKey::class)) {
            /** @var MasterKey $masterKey */
            $masterKey = $container->get(MasterKey::class);
            $hasDecryptionKey = true;

            // Encryption at rest — dedicated subkey 3 (separate from main Encryptor's subkey 1)
            $studioEncryptor = Encryptor::fromDerivedKey($masterKey, 3, 'studio_enc__');
            $encryptedStore = new EncryptedEventStore($sqliteStore, $studioEncryptor);
            $store = $encryptedStore;
            $isEncrypted = true;
            $container->instance(EncryptedEventStore::class, $encryptedStore);

            // Archive MAC key — subkey 4
            $archiveMacKey = $masterKey->deriveSubKey(4, 'studio_mac__');

            // Chain MAC key — subkey 5
            $chainMacKey = $masterKey->deriveSubKey(5, 'studio_chain_mac__');
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

        $retentionEnforcer = new RetentionEnforcer(
            $sqliteStore,
            $retentionPolicy,
            $container->has(MetricRegistry::class)
                ? $container->get(MetricRegistry::class)
                : null,
        );
        /** @var MetricRegistry|null $_ */
        $container->instance(RetentionEnforcer::class, $retentionEnforcer);

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

        // Evidence services
        $evidenceVerifier = new EvidenceVerifier();
        $container->instance(EvidenceVerifier::class, $evidenceVerifier);

        $evidenceExporter = new EvidenceExporter(
            store: $store,
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
                inner: new FpmRuntime($container->get(\Pulsar\Core\Kernel::class)),
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
        }

        // 3. Log collector (via DeferredSink)
        if ($collectorConfig->logs && $container->has(DeferredSink::class)) {
            $logCollector = new LogCollector($contextProvider, $emit);
            $container->instance(LogCollector::class, $logCollector);

            /** @var DeferredSink $deferredSink */
            $deferredSink = $container->get(DeferredSink::class);
            $deferredSink->addSink($logCollector);
        }

        // 4. Exception collector (observer on ErrorAggregator)
        if ($collectorConfig->exceptions && $container->has(ErrorAggregator::class)) {
            $exceptionCollector = new ExceptionCollector($contextProvider, $emit);
            $container->instance(ExceptionCollector::class, $exceptionCollector);

            /** @var ErrorAggregator $aggregator */
            $aggregator = $container->get(ErrorAggregator::class);
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
        if ($collectorConfig->featureFlags && $container->has(FlagEvaluationLog::class)) {
            $featureFlagCollector = new FeatureFlagCollector($contextProvider, $emit);
            $container->instance(FeatureFlagCollector::class, $featureFlagCollector);

            /** @var FlagEvaluationLog $evaluationLog */
            $evaluationLog = $container->get(FlagEvaluationLog::class);
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
}
