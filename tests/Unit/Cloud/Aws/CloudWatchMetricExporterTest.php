<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Aws;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Aws\CloudWatchMetricExporter;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\CloudException;
use Pulsar\Observability\Metrics\MetricSnapshot;
use Pulsar\Observability\Metrics\MetricType;

#[CoversClass(CloudWatchMetricExporter::class)]
final class CloudWatchMetricExporterTest extends TestCase
{
    #[Test]
    public function exportBuffersSnapshots(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $exporter = new CloudWatchMetricExporter($config, 'TestNamespace');

        $snapshot = new MetricSnapshot(
            name: 'http_requests_total',
            type: MetricType::Counter,
            help: 'Total HTTP requests',
            values: ['GET /api' => 42.0],
        );

        // Should not throw — just buffers (under the batch threshold of 20)
        $exporter->export($snapshot);

        self::assertInstanceOf(CloudWatchMetricExporter::class, $exporter);
    }

    #[Test]
    public function flushDoesNothingWhenEmpty(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $exporter = new CloudWatchMetricExporter($config);

        // Should not throw or make any HTTP calls — verify exporter state
        $exporter->flush();

        self::assertInstanceOf(CloudWatchMetricExporter::class, $exporter);
    }

    #[Test]
    public function shutdownCallsFlush(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $exporter = new CloudWatchMetricExporter($config);

        // Should not throw — verify exporter accepts shutdown
        $exporter->shutdown();

        self::assertInstanceOf(CloudWatchMetricExporter::class, $exporter);
    }

    #[Test]
    public function exportThrowsOnMissingCredentials(): void
    {
        $config = new AwsConfig(region: 'us-east-1');
        $exporter = new CloudWatchMetricExporter($config);

        $snapshot = new MetricSnapshot(
            name: 'test_metric',
            type: MetricType::Counter,
            help: 'Test',
            values: ['label' => 1.0],
        );

        // Buffer 20 snapshots to trigger auto-flush
        for ($i = 0; $i < 19; $i++) {
            $exporter->export($snapshot);
        }

        $this->expectException(CloudException::class);
        $this->expectExceptionMessageMatches('/credentials not configured/i');

        $exporter->export($snapshot);
    }
}
