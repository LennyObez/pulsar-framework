<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability;

use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Api;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\ObservabilityConfig as CoreObservabilityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Observability\Config\ObservabilityConfig;
use Pulsar\Extension\Observability\Config\OtlpProtocol;
use Pulsar\Extension\Observability\Config\SamplerType;
use Pulsar\Extension\Observability\Export\JsonLines\ErrorExporterInterface;
use Pulsar\Extension\Observability\Export\JsonLines\JsonLinesErrorExporter;
use Pulsar\Extension\Observability\Export\JsonLines\JsonLinesMetricsExporter;
use Pulsar\Extension\Observability\Export\JsonLines\JsonLinesSpanExporter;
use Pulsar\Extension\Observability\Export\JsonLines\MetricsExporterInterface;
use Pulsar\Extension\Observability\Export\JsonLines\SpanExporterInterface;
use Pulsar\Extension\Observability\Export\Otlp\LogsBatchExporter;
use Pulsar\Extension\Observability\Export\Otlp\MetricsBatchExporter;
use Pulsar\Extension\Observability\Export\Otlp\SpanBatchExporter;
use Pulsar\Extension\Observability\Export\Otlp\Transport\GrpcTransport;
use Pulsar\Extension\Observability\Export\Otlp\Transport\HttpProtobufTransport;
use Pulsar\Extension\Observability\Export\Otlp\Transport\OtlpTransportInterface;
use Pulsar\Extension\Observability\Tracing\Bridge\CompositeSpanProcessor;
use Pulsar\Extension\Observability\Tracing\Bridge\OtlpLogBridge;
use Pulsar\Extension\Observability\Tracing\Bridge\OtlpMeterBridge;
use Pulsar\Extension\Observability\Tracing\Bridge\OtlpTracerBridge;
use Pulsar\Extension\Observability\Tracing\Bridge\ResourceInfo;
use Pulsar\Extension\Observability\Tracing\Cardinality\AttributeAllowlist;
use Pulsar\Extension\Observability\Tracing\Cardinality\CardinalityLimiter;
use Pulsar\Extension\Observability\Tracing\Instrumentation\InstrumentedConnection;
use Pulsar\Extension\Observability\Tracing\Instrumentation\InstrumentedQueueDriver;
use Pulsar\Extension\Observability\Tracing\Noop\NoopLogSink;
use Pulsar\Extension\Observability\Tracing\Noop\NoopSpanProcessor;
use Pulsar\Extension\Observability\Tracing\Sampling\AlwaysSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\NeverSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\ParentBasedSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\ProbabilitySampler;
use Pulsar\Extension\Observability\Tracing\Sampling\RateLimitedSampler;
use Pulsar\Extension\Observability\Tracing\Sampling\SamplerInterface;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Observability\Log\Logger;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Tracing\SpanProcessorInterface;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Routing\RouterInterface;

use function is_array;
use function is_file;
use function register_shutdown_function;

use const DIRECTORY_SEPARATOR;

/**
 * Unified observability extension.
 *
 * Provides two export pipelines:
 * 1. OTLP: Bridges Pulsar's observability API (traces, metrics, logs) to OTLP wire
 *    format for export to OpenTelemetry Collector. No OTel SDK dependency: manual
 *    protobuf encoding with HTTP/protobuf and gRPC transport options.
 * 2. JSON Lines: File-based export for spans, metrics, and errors with buffered,
 *    multi-process-safe I/O.
 *
 * When OTLP is disabled, registers no-op processors with zero overhead.
 * JSON Lines exporters are always available when configured.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final class ObservabilityExtension implements ExtensionInterface, PreBootExtensionInterface, PostBootExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/observability';
    }

    #[Override]
    public function preBoot(ContainerInterface $container): void
    {
        // Config is loaded in register() which runs before preBoot().
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        $config = $this->loadConfig($container);
        $container->instance(ObservabilityConfig::class, $config);

        // --- JSON Lines exporters (always available when export is enabled) ---
        $this->registerJsonLinesExporters($container, $config);

        // --- OTLP pipeline ---
        if (!$config->enabled) {
            $container->instance(NoopSpanProcessor::class, new NoopSpanProcessor());
            $container->instance(NoopLogSink::class, new NoopLogSink());

            return;
        }

        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : new NullLogger();

        // Transport
        $transport = $this->buildTransport($config);
        $container->instance(OtlpTransportInterface::class, $transport);

        // Resource
        $resourceInfo = $this->buildResourceInfo($config, $container);

        // Batch exporters
        $protobufResource = $resourceInfo->toProtobuf();

        $spanExporter = new SpanBatchExporter(
            transport: $transport,
            resource: $protobufResource,
            maxBatchSize: $config->batch->maxBatchSize,
            maxQueueSize: $config->batch->maxQueueSize,
            logger: $logger,
        );
        $container->instance(SpanBatchExporter::class, $spanExporter);

        $metricsExporter = new MetricsBatchExporter(
            transport: $transport,
            resource: $protobufResource,
            maxBatchSize: $config->batch->maxBatchSize,
            maxQueueSize: $config->batch->maxQueueSize,
            logger: $logger,
        );
        $container->instance(MetricsBatchExporter::class, $metricsExporter);

        $logsExporter = new LogsBatchExporter(
            transport: $transport,
            resource: $protobufResource,
            maxBatchSize: $config->batch->maxBatchSize,
            maxQueueSize: $config->batch->maxQueueSize,
            logger: $logger,
        );
        $container->instance(LogsBatchExporter::class, $logsExporter);

        // Sampler
        $sampler = $this->buildSampler($config);
        $container->instance(SamplerInterface::class, $sampler);

        // Cardinality
        $allowlist = new AttributeAllowlist(
            allowedKeys: $config->traces->attributeAllowlist,
            maxTrackedUnknowns: $config->cardinality->maxAttributeKeys,
            logger: $logger,
        );
        $container->instance(AttributeAllowlist::class, $allowlist);

        $limiter = new CardinalityLimiter($config->cardinality->maxMetricSeries);
        $container->instance(CardinalityLimiter::class, $limiter);

        // Resource info
        $container->instance(ResourceInfo::class, $resourceInfo);

        // Bridges
        if ($config->traces->enabled) {
            $tracerBridge = new OtlpTracerBridge(
                exporter: $spanExporter,
                sampler: $sampler,
                allowlist: $allowlist,
            );
            $container->instance(OtlpTracerBridge::class, $tracerBridge);
        }

        if ($config->metrics->enabled) {
            /** @var MetricRegistry $registry */
            $registry = $container->has(MetricRegistry::class)
                ? $container->get(MetricRegistry::class)
                : new MetricRegistry();

            $meterBridge = new OtlpMeterBridge(
                registry: $registry,
                exporter: $metricsExporter,
                limiter: $limiter,
            );
            $container->instance(OtlpMeterBridge::class, $meterBridge);
        }

        if ($config->logs->enabled) {
            $scrubber = $this->buildScrubber($container);
            $correlationProvider = $container->has(CorrelationContextProviderInterface::class)
                ? $container->get(CorrelationContextProviderInterface::class)
                : null;

            $logBridge = new OtlpLogBridge(
                exporter: $logsExporter,
                scrubber: $scrubber,
                correlationProvider: $correlationProvider,
                minLevel: $config->logs->minLevel,
            );
            $container->instance(OtlpLogBridge::class, $logBridge);
        }
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // No routes needed.
    }

    #[Override]
    public function postBoot(ContainerInterface $container): void
    {
        /** @var ObservabilityConfig $config */
        $config = $container->get(ObservabilityConfig::class);

        if (!$config->enabled) {
            return;
        }

        // 1. Wire trace span processor
        if ($config->traces->enabled && $container->has(OtlpTracerBridge::class)) {
            $this->wireSpanProcessor($container, $config);
        }

        // 2. Wire log sink
        if ($config->logs->enabled && $container->has(OtlpLogBridge::class)) {
            $this->wireLogSink($container);
        }

        // 3. Decorate database connection
        if ($config->traces->enabled && $container->has(ConnectionInterface::class)) {
            $this->wireDbInstrumentation($container, $config);
        }

        // 4. Decorate queue driver
        if ($config->traces->enabled && $container->has(QueueDriverInterface::class)) {
            $this->wireQueueInstrumentation($container);
        }

        // 5. Register shutdown flush
        $this->registerShutdown($container, $config);
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [];
    }

    private function loadConfig(ContainerInterface $container): ObservabilityConfig
    {
        $environment = null;

        if ($container->has(ConfigManagerInterface::class)) {
            /** @var ConfigManagerInterface $configManager */
            $configManager = $container->get(ConfigManagerInterface::class);
            $configPath = $configManager->configPath();
            $environment = $configManager->environment();

            if ($configPath !== null) {
                // Try new config file name first, fall back to legacy
                $filePath = $configPath . DIRECTORY_SEPARATOR . 'observability.php';

                if (!is_file($filePath)) {
                    $filePath = $configPath . DIRECTORY_SEPARATOR . 'opentelemetry.php';
                }

                if (is_file($filePath)) {
                    $data = require $filePath;

                    if (is_array($data)) {
                        /** @var array<string, mixed> $data */
                        return ObservabilityConfig::fromArray($data, $environment);
                    }
                }
            }
        }

        return ObservabilityConfig::fromArray([], $environment);
    }

    private function registerJsonLinesExporters(ContainerInterface $container, ObservabilityConfig $config): void
    {
        if (!$config->export->enabled) {
            return;
        }

        $flushThreshold = $config->export->flushThreshold;

        $container->bind(
            SpanExporterInterface::class,
            static fn(): JsonLinesSpanExporter => new JsonLinesSpanExporter(
                $config->export->spansPath,
                $flushThreshold,
            ),
        );

        $container->bind(
            MetricsExporterInterface::class,
            static fn(): JsonLinesMetricsExporter => new JsonLinesMetricsExporter(
                $config->export->metricsPath,
                $flushThreshold,
            ),
        );

        $container->bind(
            ErrorExporterInterface::class,
            static fn(): JsonLinesErrorExporter => new JsonLinesErrorExporter(
                $config->export->errorsPath,
                $flushThreshold,
            ),
        );
    }

    private function buildTransport(ObservabilityConfig $config): OtlpTransportInterface
    {
        return match ($config->protocol) {
            OtlpProtocol::HttpProtobuf => new HttpProtobufTransport(
                endpoint: $config->endpoint,
                timeoutMs: $config->timeoutMs,
                headers: $config->headers,
            ),
            OtlpProtocol::Grpc => new GrpcTransport(
                endpoint: $config->endpoint,
                timeoutMs: $config->timeoutMs,
                headers: $config->headers,
            ),
        };
    }

    private function buildResourceInfo(ObservabilityConfig $config, ContainerInterface $container): ResourceInfo
    {
        if ($config->serviceName !== '') {
            return new ResourceInfo(
                serviceName: $config->serviceName,
                serviceVersion: $config->serviceVersion,
                serviceNamespace: $config->serviceNamespace,
            );
        }

        if ($container->has(AppConfig::class)) {
            /** @var AppConfig $appConfig */
            $appConfig = $container->get(AppConfig::class);

            return ResourceInfo::fromAppConfig($appConfig, $config->serviceVersion, $config->serviceNamespace);
        }

        return new ResourceInfo(serviceName: 'unknown');
    }

    private function buildSampler(ObservabilityConfig $config): SamplerInterface
    {
        return match ($config->sampler->type) {
            SamplerType::Always => new AlwaysSampler(),
            SamplerType::Never => new NeverSampler(),
            SamplerType::Probability => new ProbabilitySampler($config->sampler->probability),
            SamplerType::RateLimited => new RateLimitedSampler($config->sampler->ratePerSecond),
            SamplerType::ParentBased => new ParentBasedSampler(
                new ProbabilitySampler($config->sampler->probability),
            ),
        };
    }

    private function buildScrubber(ContainerInterface $container): SensitiveDataScrubber
    {
        if ($container->has(SensitiveDataScrubber::class)) {
            /** @var SensitiveDataScrubber */
            return $container->get(SensitiveDataScrubber::class);
        }

        return new SensitiveDataScrubber();
    }

    private function wireSpanProcessor(ContainerInterface $container, ObservabilityConfig $config): void
    {
        /** @var OtlpTracerBridge $bridge */
        $bridge = $container->get(OtlpTracerBridge::class);

        if ($config->dualExport && $container->has(SpanProcessorInterface::class)) {
            /** @var SpanProcessorInterface $existing */
            $existing = $container->get(SpanProcessorInterface::class);
            $composite = new CompositeSpanProcessor([$existing, $bridge]);
            $container->instance(SpanProcessorInterface::class, $composite);
        } else {
            $container->instance(SpanProcessorInterface::class, $bridge);
        }
    }

    private function wireLogSink(ContainerInterface $container): void
    {
        if (!$container->has(CoreObservabilityConfig::class)) {
            return;
        }

        /** @var OtlpLogBridge $logBridge */
        $logBridge = $container->get(OtlpLogBridge::class);

        /** @var CoreObservabilityConfig $obsConfig */
        $obsConfig = $container->get(CoreObservabilityConfig::class);

        $logger = Logger::fromConfigWithExtraSinks($obsConfig, [$logBridge]);
        $container->instance(LoggerInterface::class, $logger);
    }

    private function wireDbInstrumentation(ContainerInterface $container, ObservabilityConfig $config): void
    {
        /** @var ConnectionInterface $inner */
        $inner = $container->get(ConnectionInterface::class);

        /** @var SpanProcessorInterface $processor */
        $processor = $container->get(SpanProcessorInterface::class);

        $traceContext = TraceContext::create();

        $regulatedMode = false;

        if ($container->has(AppConfig::class)) {
            /** @var AppConfig $appConfig */
            $appConfig = $container->get(AppConfig::class);
            $regulatedMode = $appConfig->mode === EnvironmentMode::Production;
        }

        $instrumented = new InstrumentedConnection(
            inner: $inner,
            processor: $processor,
            traceContext: $traceContext,
            enabled: true,
            statementExport: $config->traces->dbStatementExport,
            regulatedMode: $regulatedMode,
        );

        $container->instance(ConnectionInterface::class, $instrumented);
    }

    private function wireQueueInstrumentation(ContainerInterface $container): void
    {
        /** @var QueueDriverInterface $inner */
        $inner = $container->get(QueueDriverInterface::class);

        /** @var SpanProcessorInterface $processor */
        $processor = $container->get(SpanProcessorInterface::class);

        $traceContext = TraceContext::create();

        $instrumented = new InstrumentedQueueDriver(
            inner: $inner,
            processor: $processor,
            traceContext: $traceContext,
            enabled: true,
        );

        $container->instance(QueueDriverInterface::class, $instrumented);
    }

    private function registerShutdown(ContainerInterface $container, ObservabilityConfig $config): void
    {
        register_shutdown_function(static function () use ($container, $config): void {
            if ($config->metrics->enabled && $container->has(OtlpMeterBridge::class)) {
                /** @var OtlpMeterBridge $meterBridge */
                $meterBridge = $container->get(OtlpMeterBridge::class);
                $meterBridge->collect();
            }

            if ($container->has(SpanBatchExporter::class)) {
                /** @var SpanBatchExporter $spanExporter */
                $spanExporter = $container->get(SpanBatchExporter::class);
                $spanExporter->shutdown();
            }

            if ($container->has(MetricsBatchExporter::class)) {
                /** @var MetricsBatchExporter $metricsExporter */
                $metricsExporter = $container->get(MetricsBatchExporter::class);
                $metricsExporter->shutdown();
            }

            if ($container->has(LogsBatchExporter::class)) {
                /** @var LogsBatchExporter $logsExporter */
                $logsExporter = $container->get(LogsBatchExporter::class);
                $logsExporter->shutdown();
            }
        });
    }
}
