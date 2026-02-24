<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Config\BatchConfig;
use Pulsar\Extension\OpenTelemetry\Config\CardinalityConfig;
use Pulsar\Extension\OpenTelemetry\Config\DbStatementExport;
use Pulsar\Extension\OpenTelemetry\Config\OtlpProtocol;
use Pulsar\Extension\OpenTelemetry\Config\SamplerConfig;
use Pulsar\Extension\OpenTelemetry\Config\SamplerType;

#[CoversClass(BatchConfig::class)]
#[CoversClass(CardinalityConfig::class)]
#[CoversClass(DbStatementExport::class)]
#[CoversClass(OtlpProtocol::class)]
#[CoversClass(SamplerConfig::class)]
#[CoversClass(SamplerType::class)]
final class OtelSubConfigsTest extends TestCase
{
    // --- BatchConfig ---

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
            'max_batch_size' => 256,
            'max_queue_size' => 1024,
        ]);

        self::assertSame(256, $config->maxBatchSize);
        self::assertSame(1024, $config->maxQueueSize);
    }

    #[Test]
    public function batchConfigInvalidValuesUseDefaults(): void
    {
        $config = BatchConfig::fromArray([
            'max_batch_size' => 'not-an-int',
            'max_queue_size' => false,
        ]);

        self::assertSame(512, $config->maxBatchSize);
        self::assertSame(2048, $config->maxQueueSize);
    }

    // --- CardinalityConfig ---

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

    // --- SamplerConfig ---

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
            'probability' => 0.5,
            'rate_per_second' => 50.0,
        ]);

        self::assertSame(SamplerType::Probability, $config->type);
        self::assertSame(0.5, $config->probability);
        self::assertSame(50.0, $config->ratePerSecond);
    }

    #[Test]
    public function samplerConfigInvalidTypeDefaultsToParentBased(): void
    {
        $config = SamplerConfig::fromArray(['type' => 'unknown']);

        self::assertSame(SamplerType::ParentBased, $config->type);
    }

    // --- SamplerType ---

    #[Test]
    public function samplerTypeValues(): void
    {
        self::assertSame('always', SamplerType::Always->value);
        self::assertSame('never', SamplerType::Never->value);
        self::assertSame('probability', SamplerType::Probability->value);
        self::assertSame('rate_limited', SamplerType::RateLimited->value);
        self::assertSame('parent_based', SamplerType::ParentBased->value);
    }

    #[Test]
    public function samplerTypeCaseCount(): void
    {
        self::assertCount(5, SamplerType::cases());
    }

    // --- OtlpProtocol ---

    #[Test]
    public function otlpProtocolValues(): void
    {
        self::assertSame('http/protobuf', OtlpProtocol::HttpProtobuf->value);
        self::assertSame('grpc', OtlpProtocol::Grpc->value);
    }

    #[Test]
    public function otlpProtocolCaseCount(): void
    {
        self::assertCount(2, OtlpProtocol::cases());
    }

    // --- DbStatementExport ---

    #[Test]
    public function dbStatementExportValues(): void
    {
        self::assertSame('none', DbStatementExport::None->value);
        self::assertSame('hash', DbStatementExport::Hash->value);
        self::assertSame('full', DbStatementExport::Full->value);
    }

    #[Test]
    public function dbStatementExportCaseCount(): void
    {
        self::assertCount(3, DbStatementExport::cases());
    }
}
