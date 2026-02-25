<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Export;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\MetricsRequestBuilder;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpMetric;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\OtlpTransportInterface;

/**
 * Batch exporter for metrics.
 *
 * Wraps BatchExporter with MetricsRequestBuilder serialization and
 * the standard OTLP /v1/metrics endpoint path.
 */
#[Internal(reason: 'Metrics-specific batch export wiring')]
final readonly class MetricsBatchExporter
{
    /** @var BatchExporter<OtlpMetric> */
    private BatchExporter $batchExporter;

    public function __construct(
        OtlpTransportInterface $transport,
        ResourceInfo $resource,
        MetricsRequestBuilder $builder = new MetricsRequestBuilder(),
        int $maxBatchSize = 512,
        int $maxQueueSize = 2048,
        LoggerInterface $logger = new NullLogger(),
    ) {
        /** @var BatchExporter<OtlpMetric> $batchExporter */
        $batchExporter = new BatchExporter(
            transport: $transport,
            serializer: static function (array $metrics) use ($builder, $resource): string {
                /** @var list<OtlpMetric> $metrics */
                return $builder->build($metrics, $resource);
            },
            signalPath: '/v1/metrics',
            maxBatchSize: $maxBatchSize,
            maxQueueSize: $maxQueueSize,
            logger: $logger,
        );
        $this->batchExporter = $batchExporter;
    }

    public function enqueue(OtlpMetric $metric): void
    {
        $this->batchExporter->enqueue($metric);
    }

    public function flush(): void
    {
        $this->batchExporter->flush();
    }

    public function shutdown(): void
    {
        $this->batchExporter->shutdown();
    }

    public function queueSize(): int
    {
        return $this->batchExporter->queueSize();
    }
}
