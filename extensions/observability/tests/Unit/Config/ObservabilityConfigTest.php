<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Config\BatchConfig;
use Pulsar\Extension\Observability\Config\CardinalityConfig;
use Pulsar\Extension\Observability\Config\DbStatementExport;
use Pulsar\Extension\Observability\Config\ExportConfig;
use Pulsar\Extension\Observability\Config\LogsConfig;
use Pulsar\Extension\Observability\Config\MetricsConfig;
use Pulsar\Extension\Observability\Config\ObservabilityConfig;
use Pulsar\Extension\Observability\Config\OtlpProtocol;
use Pulsar\Extension\Observability\Config\SamplerConfig;
use Pulsar\Extension\Observability\Config\SamplerType;
use Pulsar\Extension\Observability\Config\TracingConfig;

#[CoversClass(ObservabilityConfig::class)]
#[CoversClass(TracingConfig::class)]
#[CoversClass(MetricsConfig::class)]
#[CoversClass(LogsConfig::class)]
#[CoversClass(SamplerConfig::class)]
#[CoversClass(BatchConfig::class)]
#[CoversClass(CardinalityConfig::class)]
#[CoversClass(ExportConfig::class)]
final class ObservabilityConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreCorrect(): void
    {
        $config = ObservabilityConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('http://localhost:4318', $config->endpoint);
        self::assertSame(OtlpProtocol::HttpProtobuf, $config->protocol);
        self::assertSame(5000, $config->timeoutMs);
        self::assertSame([], $config->headers);
        self::assertSame('', $config->serviceName);
        self::assertTrue($config->traces->enabled);
        self::assertTrue($config->metrics->enabled);
        self::assertTrue($config->logs->enabled);
        self::assertSame(SamplerType::ParentBased, $config->sampler->type);
        self::assertSame(512, $config->batch->maxBatchSize);
        self::assertSame(2048, $config->batch->maxQueueSize);
        self::assertFalse($config->dualExport);
        self::assertTrue($config->export->enabled);
    }

    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $config = ObservabilityConfig::fromArray([
            'enabled' => true,
            'endpoint' => 'https://otel.example.com:4317',
            'protocol' => 'grpc',
            'timeout_ms' => 10000,
            'service_name' => 'my-service',
            'service_version' => '2.0.0',
            'dual_export' => true,
            'traces' => [
                'enabled' => true,
                'db_statement_export' => 'hash',
            ],
            'metrics' => [
                'enabled' => false,
                'collect_interval_ms' => 30000,
            ],
            'sampler' => [
                'type' => 'probability',
                'probability' => 0.5,
            ],
            'batch' => [
                'max_batch_size' => 256,
                'max_queue_size' => 1024,
            ],
            'cardinality' => [
                'max_attribute_keys' => 500,
                'max_metric_series' => 1000,
            ],
            'export' => [
                'enabled' => false,
                'spans_path' => '/tmp/spans.jsonl',
            ],
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('https://otel.example.com:4317', $config->endpoint);
        self::assertSame(OtlpProtocol::Grpc, $config->protocol);
        self::assertSame(10000, $config->timeoutMs);
        self::assertSame('my-service', $config->serviceName);
        self::assertTrue($config->dualExport);
        self::assertSame(DbStatementExport::Hash, $config->traces->dbStatementExport);
        self::assertFalse($config->metrics->enabled);
        self::assertSame(30000, $config->metrics->collectIntervalMs);
        self::assertSame(SamplerType::Probability, $config->sampler->type);
        self::assertSame(0.5, $config->sampler->probability);
        self::assertSame(256, $config->batch->maxBatchSize);
        self::assertSame(1024, $config->batch->maxQueueSize);
        self::assertSame(500, $config->cardinality->maxAttributeKeys);
        self::assertFalse($config->export->enabled);
        self::assertSame('/tmp/spans.jsonl', $config->export->spansPath);
    }

    #[Test]
    public function invalidTypesUsedDefaults(): void
    {
        $config = ObservabilityConfig::fromArray([
            'enabled' => 'yes',
            'endpoint' => 42,
            'timeout_ms' => 'slow',
            'protocol' => 'invalid',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('http://localhost:4318', $config->endpoint);
        self::assertSame(5000, $config->timeoutMs);
        self::assertSame(OtlpProtocol::HttpProtobuf, $config->protocol);
    }

    #[Test]
    public function exportConfigDefaults(): void
    {
        $config = ExportConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertSame('var/observability/spans.jsonl', $config->spansPath);
        self::assertSame('var/observability/metrics.jsonl', $config->metricsPath);
        self::assertSame('var/observability/errors.jsonl', $config->errorsPath);
        self::assertSame(10, $config->flushThreshold);
    }
}
