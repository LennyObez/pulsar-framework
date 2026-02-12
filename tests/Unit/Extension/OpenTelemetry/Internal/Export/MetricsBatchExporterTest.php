<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Internal\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Export\BatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Export\MetricsBatchExporter;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpMetric;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\OtlpTransportInterface;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\TransportResult;
use Pulsar\Observability\Metrics\MetricType;

#[CoversClass(MetricsBatchExporter::class)]
#[CoversClass(BatchExporter::class)]
final class MetricsBatchExporterTest extends TestCase
{
    #[Test]
    public function enqueueAndFlushSendsToMetricsEndpoint(): void
    {
        $sentPayloads = [];
        $transport = $this->createTransport($sentPayloads);

        $exporter = new MetricsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(['service.name' => 'test']),
            maxBatchSize: 100,
        );

        $exporter->enqueue(new OtlpMetric(
            name: 'request_count',
            description: 'Total requests',
            unit: '1',
            type: MetricType::Counter,
            dataPoints: [
                ['time_unix_nano' => 1_700_000_000_000_000_000, 'value' => 10.0, 'attributes' => []],
            ],
        ));

        $exporter->flush();

        self::assertSame(0, $exporter->queueSize());
        self::assertCount(1, $sentPayloads);
        self::assertSame('/v1/metrics', $sentPayloads[0]['path']);
        self::assertStringContainsString('request_count', $sentPayloads[0]['payload']);
    }

    #[Test]
    public function shutdownFlushesRemaining(): void
    {
        $sentPayloads = [];
        $transport = $this->createTransport($sentPayloads);

        $exporter = new MetricsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 100,
        );

        $exporter->enqueue(new OtlpMetric(
            name: 'gauge_final',
            description: '',
            unit: '',
            type: MetricType::Gauge,
            dataPoints: [
                ['time_unix_nano' => 1_700_000_000_000_000_000, 'value' => 42.0, 'attributes' => []],
            ],
        ));

        $exporter->shutdown();

        self::assertCount(1, $sentPayloads);
        self::assertStringContainsString('gauge_final', $sentPayloads[0]['payload']);
    }

    /** @param list<array{path: string, payload: string}> $sentPayloads */
    private function createTransport(array &$sentPayloads = []): OtlpTransportInterface
    {
        return new class ($sentPayloads) implements OtlpTransportInterface {
            /** @param list<array{path: string, payload: string}> $sent */
            public function __construct(public array &$sent) {}

            public function send(string $path, string $protobufPayload): TransportResult
            {
                $this->sent[] = ['path' => $path, 'payload' => $protobufPayload];
                return TransportResult::success(200);
            }
        };
    }
}
