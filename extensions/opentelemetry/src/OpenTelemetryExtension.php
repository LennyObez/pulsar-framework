<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry;

use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Api;
use Pulsar\Config\AppConfig;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\OpenTelemetry\Bridge\CompositeSpanProcessor;
use Pulsar\Extension\OpenTelemetry\Bridge\OtlpLogBridge;
use Pulsar\Extension\OpenTelemetry\Bridge\OtlpMeterBridge;
use Pulsar\Extension\OpenTelemetry\Bridge\OtlpTracerBridge;
use Pulsar\Extension\OpenTelemetry\Bridge\ResourceInfo;
use Pulsar\Extension\OpenTelemetry\Cardinality\AttributeAllowlist;
use Pulsar\Extension\OpenTelemetry\Cardinality\CardinalityLimiter;
use Pulsar\Extension\OpenTelemetry\Config\OpenTelemetryConfig;
use Pulsar\Extension\OpenTelemetry\Config\OtlpProtocol;
use Pulsar\Extension\OpenTelemetry\Config\SamplerType;
use Pulsar\Extension\OpenTelemetry\Instrumentation\InstrumentedConnection;
use Pulsar\Extension\OpenTelemetry\Instrumentation\InstrumentedQueueDriver;
use Pulsar\Extension\OpenTelemetry\Internal\Export\LogsBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Export\MetricsBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Export\SpanBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\GrpcTransport;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\HttpProtobufTransport;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\OtlpTransportInterface;
use Pulsar\Extension\OpenTelemetry\Noop\NoopLogSink;
use Pulsar\Extension\OpenTelemetry\Noop\NoopSpanProcessor;
use Pulsar\Extension\OpenTelemetry\Sampling\AlwaysSampler;
use Pulsar\Extension\OpenTelemetry\Sampling\NeverSampler;
use Pulsar\Extension\OpenTelemetry\Sampling\ParentBasedSampler;
use Pulsar\Extension\OpenTelemetry\Sampling\ProbabilitySampler;
use Pulsar\Extension\OpenTelemetry\Sampling\RateLimitedSampler;
use Pulsar\Extension\OpenTelemetry\Sampling\SamplerInterface;
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
 * OpenTelemetry OTLP export extension.
 *
 * Bridges Pulsar's observability API (traces, metrics, logs) to OTLP wire format
 * for export to OpenTelemetry Collector. No OTel SDK dependency: manual protobuf
 * encoding with two transport options: HTTP/protobuf and gRPC.
 *
 * When disabled, registers no-op processors with zero overhead.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final class OpenTelemetryExtension implements ExtensionInterface, PreBootExtensionInterface, PostBootExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/opentelemetry';
    }

    #[Override]
    public function preBoot(ContainerInterface $container): void
    {
        // Config is loaded in register() which runs before preBoot().
        // This method is intentionally empty.
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        $config = $this->loadConfig($container);
        $container->instance(OpenTelemetryConfig::class, $config);

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
        /** @var OpenTelemetryConfig $config */
        $config = $container->get(OpenTelemetryConfig::class);

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

    private function loadConfig(ContainerInterface $container): OpenTelemetryConfig
    {
        $environment = null;

        if ($container->has(ConfigManagerInterface::class)) {
            /** @var ConfigManagerInterface $configManager */
            $configManager = $container->get(ConfigManagerInterface::class);
            $configPath = $configManager->configPath();
            $environment = $configManager->environment();

            if ($configPath !== null) {
                $filePath = $configPath . DIRECTORY_SEPARATOR . 'opentelemetry.php';

                if (is_file($filePath)) {
                    /**
                     * @var mixed $data
                     */
                    $data = require $filePath;

                    if (is_array($data)) {
                        /** @var array<string, mixed> $data */
                        return OpenTelemetryConfig::fromArray($data, $environment);
                    }
                }
            }
        }

        return OpenTelemetryConfig::fromArray([], $environment);
    }

    private function buildTransport(OpenTelemetryConfig $config): OtlpTransportInterface
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

    private function buildResourceInfo(OpenTelemetryConfig $config, ContainerInterface $container): ResourceInfo
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

    private function buildSampler(OpenTelemetryConfig $config): SamplerInterface
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

    private function wireSpanProcessor(ContainerInterface $container, OpenTelemetryConfig $config): void
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
        if (!$container->has(ObservabilityConfig::class)) {
            return;
        }

        /** @var OtlpLogBridge $logBridge */
        $logBridge = $container->get(OtlpLogBridge::class);

        /** @var ObservabilityConfig $obsConfig */
        $obsConfig = $container->get(ObservabilityConfig::class);

        $logger = Logger::fromConfigWithExtraSinks($obsConfig, [$logBridge]);
        $container->instance(LoggerInterface::class, $logger);
    }

    private function wireDbInstrumentation(ContainerInterface $container, OpenTelemetryConfig $config): void
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

    private function registerShutdown(ContainerInterface $container, OpenTelemetryConfig $config): void
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
