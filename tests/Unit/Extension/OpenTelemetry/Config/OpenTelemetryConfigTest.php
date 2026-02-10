<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\OpenTelemetry\Config\BatchConfig;
use Pulsar\Extension\OpenTelemetry\Config\CardinalityConfig;
use Pulsar\Extension\OpenTelemetry\Config\DbStatementExport;
use Pulsar\Extension\OpenTelemetry\Config\OpenTelemetryConfig;
use Pulsar\Extension\OpenTelemetry\Config\OtlpLogsConfig;
use Pulsar\Extension\OpenTelemetry\Config\OtlpMetricsConfig;
use Pulsar\Extension\OpenTelemetry\Config\OtlpProtocol;
use Pulsar\Extension\OpenTelemetry\Config\OtlpTracesConfig;
use Pulsar\Extension\OpenTelemetry\Config\SamplerConfig;
use Pulsar\Extension\OpenTelemetry\Config\SamplerType;
use Pulsar\Observability\Log\LogLevel;

#[CoversClass(OpenTelemetryConfig::class)]
#[CoversClass(OtlpTracesConfig::class)]
#[CoversClass(OtlpMetricsConfig::class)]
#[CoversClass(OtlpLogsConfig::class)]
#[CoversClass(SamplerConfig::class)]
#[CoversClass(BatchConfig::class)]
#[CoversClass(CardinalityConfig::class)]
final class OpenTelemetryConfigTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL');
        putenv('OTEL_SERVICE_NAME');
        putenv('OTEL_TRACES_SAMPLER');
        putenv('OTEL_TRACES_SAMPLER_ARG');
        putenv('OTEL_EXPORTER_OTLP_HEADERS');
    }

    protected function tearDown(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL');
        putenv('OTEL_SERVICE_NAME');
        putenv('OTEL_TRACES_SAMPLER');
        putenv('OTEL_TRACES_SAMPLER_ARG');
        putenv('OTEL_EXPORTER_OTLP_HEADERS');
    }

    #[Test]
    public function defaultsAppliedFromEmptyArray(): void
    {
        $config = OpenTelemetryConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('http://localhost:4318', $config->endpoint);
        self::assertSame(OtlpProtocol::HttpProtobuf, $config->protocol);
        self::assertSame(5000, $config->timeoutMs);
        self::assertSame([], $config->headers);
        self::assertSame('', $config->serviceName);
        self::assertSame('', $config->serviceVersion);
        self::assertSame('', $config->serviceNamespace);
        self::assertTrue($config->traces->enabled);
        self::assertTrue($config->metrics->enabled);
        self::assertTrue($config->logs->enabled);
        self::assertSame(SamplerType::ParentBased, $config->sampler->type);
        self::assertSame(1.0, $config->sampler->probability);
        self::assertSame(['tracecontext', 'baggage'], $config->propagators);
        self::assertSame(512, $config->batch->maxBatchSize);
        self::assertSame(2048, $config->batch->maxQueueSize);
        self::assertSame(1000, $config->cardinality->maxAttributeKeys);
        self::assertFalse($config->dualExport);
    }

    #[Test]
    public function constructsFromFullArray(): void
    {
        $config = OpenTelemetryConfig::fromArray([
            'enabled' => true,
            'endpoint' => 'http://collector:4318',
            'protocol' => 'grpc',
            'timeout_ms' => 10000,
            'headers' => ['Authorization' => 'Bearer token'],
            'service_name' => 'my-service',
            'service_version' => '2.0.0',
            'service_namespace' => 'production',
            'traces' => [
                'enabled' => true,
                'endpoint' => 'http://traces:4318',
                'attribute_allowlist' => ['http' => ['http.method', 'http.status_code']],
                'db_statement_export' => 'hash',
            ],
            'metrics' => [
                'enabled' => false,
                'endpoint' => 'http://metrics:4318',
                'collect_interval_ms' => 30000,
            ],
            'logs' => [
                'enabled' => true,
                'endpoint' => 'http://logs:4318',
                'min_level' => 'error',
            ],
            'sampler' => [
                'type' => 'probability',
                'probability' => 0.5,
                'rate_per_second' => 50.0,
            ],
            'propagators' => ['tracecontext'],
            'batch' => [
                'max_batch_size' => 256,
                'max_queue_size' => 1024,
            ],
            'cardinality' => [
                'max_attribute_keys' => 500,
                'max_metric_series' => 1000,
                'normalize_urls' => false,
            ],
            'dual_export' => true,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('http://collector:4318', $config->endpoint);
        self::assertSame(OtlpProtocol::Grpc, $config->protocol);
        self::assertSame(10000, $config->timeoutMs);
        self::assertSame(['Authorization' => 'Bearer token'], $config->headers);
        self::assertSame('my-service', $config->serviceName);
        self::assertSame('2.0.0', $config->serviceVersion);
        self::assertSame('production', $config->serviceNamespace);

        self::assertTrue($config->traces->enabled);
        self::assertSame('http://traces:4318', $config->traces->endpoint);
        self::assertSame(['http' => ['http.method', 'http.status_code']], $config->traces->attributeAllowlist);
        self::assertSame(DbStatementExport::Hash, $config->traces->dbStatementExport);

        self::assertFalse($config->metrics->enabled);
        self::assertSame('http://metrics:4318', $config->metrics->endpoint);
        self::assertSame(30000, $config->metrics->collectIntervalMs);

        self::assertTrue($config->logs->enabled);
        self::assertSame('http://logs:4318', $config->logs->endpoint);
        self::assertSame(LogLevel::Error, $config->logs->minLevel);

        self::assertSame(SamplerType::Probability, $config->sampler->type);
        self::assertSame(0.5, $config->sampler->probability);
        self::assertSame(50.0, $config->sampler->ratePerSecond);

        self::assertSame(['tracecontext'], $config->propagators);

        self::assertSame(256, $config->batch->maxBatchSize);
        self::assertSame(1024, $config->batch->maxQueueSize);

        self::assertSame(500, $config->cardinality->maxAttributeKeys);
        self::assertSame(1000, $config->cardinality->maxMetricSeries);
        self::assertFalse($config->cardinality->normalizeUrls);

        self::assertTrue($config->dualExport);
    }

    #[Test]
    public function envVarOverridesEndpoint(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://env-collector:4318');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([
            'endpoint' => 'http://file-collector:4318',
        ], $env);

        self::assertSame('http://env-collector:4318', $config->endpoint);
    }

    #[Test]
    public function envVarOverridesProtocol(): void
    {
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=grpc');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([
            'protocol' => 'http/protobuf',
        ], $env);

        self::assertSame(OtlpProtocol::Grpc, $config->protocol);
    }

    #[Test]
    public function envVarOverridesServiceName(): void
    {
        putenv('OTEL_SERVICE_NAME=env-service');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([
            'service_name' => 'file-service',
        ], $env);

        self::assertSame('env-service', $config->serviceName);
    }

    #[Test]
    public function envVarOverridesSamplerType(): void
    {
        putenv('OTEL_TRACES_SAMPLER=always_on');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([
            'sampler' => ['type' => 'never'],
        ], $env);

        self::assertSame(SamplerType::Always, $config->sampler->type);
    }

    #[Test]
    public function envVarOverridesSamplerArg(): void
    {
        putenv('OTEL_TRACES_SAMPLER=traceidratio');
        putenv('OTEL_TRACES_SAMPLER_ARG=0.25');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame(SamplerType::Probability, $config->sampler->type);
        self::assertSame(0.25, $config->sampler->probability);
    }

    #[Test]
    public function envVarOverridesHeaders(): void
    {
        putenv('OTEL_EXPORTER_OTLP_HEADERS=x-api-key=abc123,x-tenant=acme');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([
            'headers' => ['old-key' => 'old-value'],
        ], $env);

        self::assertSame(['x-api-key' => 'abc123', 'x-tenant' => 'acme'], $config->headers);
    }

    #[Test]
    public function invalidSamplerArgPreservesFileValue(): void
    {
        putenv('OTEL_TRACES_SAMPLER=traceidratio');
        putenv('OTEL_TRACES_SAMPLER_ARG=2.0');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([
            'sampler' => ['probability' => 0.75],
        ], $env);

        // 2.0 is out of [0, 1] range — file value is preserved
        self::assertSame(0.75, $config->sampler->probability);
    }

    #[Test]
    public function invalidProtocolEnvVarPreservesFileValue(): void
    {
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=invalid-protocol');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([
            'protocol' => 'grpc',
        ], $env);

        self::assertSame(OtlpProtocol::Grpc, $config->protocol);
    }

    #[Test]
    public function withoutEnvironmentNoOverridesApplied(): void
    {
        // When no Environment is passed, only file values are used
        $config = OpenTelemetryConfig::fromArray([
            'endpoint' => 'http://file-only:4318',
            'service_name' => 'file-service',
        ]);

        self::assertSame('http://file-only:4318', $config->endpoint);
        self::assertSame('file-service', $config->serviceName);
    }

    #[Test]
    public function parentBasedSamplerMappings(): void
    {
        putenv('OTEL_TRACES_SAMPLER=parentbased_always_on');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);
        self::assertSame(SamplerType::ParentBased, $config->sampler->type);
    }

    #[Test]
    public function alwaysOffSamplerMapping(): void
    {
        putenv('OTEL_TRACES_SAMPLER=always_off');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);
        self::assertSame(SamplerType::Never, $config->sampler->type);
    }

    #[Test]
    public function batchConfigDefaults(): void
    {
        $config = BatchConfig::fromArray([]);

        self::assertSame(512, $config->maxBatchSize);
        self::assertSame(2048, $config->maxQueueSize);
    }

    #[Test]
    public function batchConfigFromArray(): void
    {
        $config = BatchConfig::fromArray([
            'max_batch_size' => 100,
            'max_queue_size' => 500,
        ]);

        self::assertSame(100, $config->maxBatchSize);
        self::assertSame(500, $config->maxQueueSize);
    }

    #[Test]
    public function cardinalityConfigDefaults(): void
    {
        $config = CardinalityConfig::fromArray([]);

        self::assertSame(1000, $config->maxAttributeKeys);
        self::assertSame(2000, $config->maxMetricSeries);
        self::assertTrue($config->normalizeUrls);
    }

    #[Test]
    public function cardinalityConfigFromArray(): void
    {
        $config = CardinalityConfig::fromArray([
            'max_attribute_keys' => 500,
            'max_metric_series' => 1000,
            'normalize_urls' => false,
        ]);

        self::assertSame(500, $config->maxAttributeKeys);
        self::assertSame(1000, $config->maxMetricSeries);
        self::assertFalse($config->normalizeUrls);
    }

    #[Test]
    public function samplerConfigDefaults(): void
    {
        $config = SamplerConfig::fromArray([]);

        self::assertSame(SamplerType::ParentBased, $config->type);
        self::assertSame(1.0, $config->probability);
        self::assertSame(100.0, $config->ratePerSecond);
    }

    #[Test]
    public function samplerConfigFromArray(): void
    {
        $config = SamplerConfig::fromArray([
            'type' => 'probability',
            'probability' => 0.1,
            'rate_per_second' => 50.0,
        ]);

        self::assertSame(SamplerType::Probability, $config->type);
        self::assertSame(0.1, $config->probability);
        self::assertSame(50.0, $config->ratePerSecond);
    }

    #[Test]
    public function tracesConfigDefaults(): void
    {
        $config = OtlpTracesConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame([], $config->attributeAllowlist);
        self::assertSame(DbStatementExport::None, $config->dbStatementExport);
    }

    #[Test]
    public function tracesConfigFromArray(): void
    {
        $config = OtlpTracesConfig::fromArray([
            'enabled' => false,
            'endpoint' => 'http://traces:4318',
            'attribute_allowlist' => ['db' => ['db.system']],
            'db_statement_export' => 'full',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('http://traces:4318', $config->endpoint);
        self::assertSame(['db' => ['db.system']], $config->attributeAllowlist);
        self::assertSame(DbStatementExport::Full, $config->dbStatementExport);
    }

    #[Test]
    public function metricsConfigDefaults(): void
    {
        $config = OtlpMetricsConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame(60_000, $config->collectIntervalMs);
    }

    #[Test]
    public function metricsConfigFromArray(): void
    {
        $config = OtlpMetricsConfig::fromArray([
            'enabled' => false,
            'endpoint' => 'http://metrics:4318',
            'collect_interval_ms' => 30000,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('http://metrics:4318', $config->endpoint);
        self::assertSame(30000, $config->collectIntervalMs);
    }

    #[Test]
    public function logsConfigDefaults(): void
    {
        $config = OtlpLogsConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('', $config->endpoint);
        self::assertSame(LogLevel::Warning, $config->minLevel);
    }

    #[Test]
    public function logsConfigFromArray(): void
    {
        $config = OtlpLogsConfig::fromArray([
            'enabled' => false,
            'endpoint' => 'http://logs:4318',
            'min_level' => 'debug',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('http://logs:4318', $config->endpoint);
        self::assertSame(LogLevel::Debug, $config->minLevel);
    }

    #[Test]
    public function invalidTypesUseFallbackDefaults(): void
    {
        $config = OpenTelemetryConfig::fromArray([
            'enabled' => 'not-a-bool',
            'endpoint' => 42,
            'protocol' => 'invalid',
            'timeout_ms' => 'not-int',
            'headers' => 'not-array',
            'service_name' => 123,
            'propagators' => 'not-array',
            'dual_export' => 'not-a-bool',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('http://localhost:4318', $config->endpoint);
        self::assertSame(OtlpProtocol::HttpProtobuf, $config->protocol);
        self::assertSame(5000, $config->timeoutMs);
        self::assertSame([], $config->headers);
        self::assertSame('', $config->serviceName);
        self::assertSame(['tracecontext', 'baggage'], $config->propagators);
        self::assertFalse($config->dualExport);
    }

    #[Test]
    public function headerParsingHandlesEdgeCases(): void
    {
        putenv('OTEL_EXPORTER_OTLP_HEADERS=key=value,,empty-no-equals,  spaced = val ');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame('value', $config->headers['key']);
        self::assertSame('val', $config->headers['spaced']);
        self::assertArrayNotHasKey('empty-no-equals', $config->headers);
    }

    #[Test]
    public function samplerConfigHandlesIntegerProbability(): void
    {
        $config = SamplerConfig::fromArray([
            'probability' => 1,
            'rate_per_second' => 50,
        ]);

        self::assertSame(1.0, $config->probability);
        self::assertSame(50.0, $config->ratePerSecond);
    }
}
