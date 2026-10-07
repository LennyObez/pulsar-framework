<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExportTests\Unit\Metrics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\ObservabilityExport\Metrics\JsonLinesMetricsExporter;
use Pulsar\Observability\Metrics\MetricSnapshot;
use Pulsar\Observability\Metrics\MetricType;

final class JsonLinesMetricsExporterTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_metrics_') ?: '/tmp/pulsar_metrics_test';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    #[Test]
    public function exportBuffersSnapshots(): void
    {
        $exporter = new JsonLinesMetricsExporter($this->tmpFile, flushThreshold: 5);
        $exporter->export($this->createSnapshot());

        self::assertCount(1, $exporter->buffer());
    }

    #[Test]
    public function exportFlushesAtThreshold(): void
    {
        $exporter = new JsonLinesMetricsExporter($this->tmpFile, flushThreshold: 2);
        $exporter->export($this->createSnapshot());
        $exporter->export($this->createSnapshot());

        self::assertCount(0, $exporter->buffer());
    }

    #[Test]
    public function flushWritesAllBufferedSnapshots(): void
    {
        $exporter = new JsonLinesMetricsExporter($this->tmpFile, flushThreshold: 100);
        $exporter->export($this->createSnapshot('m1'));
        $exporter->export($this->createSnapshot('m2'));
        $exporter->export($this->createSnapshot('m3'));
        $exporter->flush();

        $lines = array_filter(explode("\n", file_get_contents($this->tmpFile) ?: ''));
        self::assertCount(3, $lines);
    }

    #[Test]
    public function shutdownFlushesAndDisablesExport(): void
    {
        $exporter = new JsonLinesMetricsExporter($this->tmpFile, flushThreshold: 100);
        $exporter->export($this->createSnapshot());
        $exporter->shutdown();
        $exporter->export($this->createSnapshot());

        $lines = array_filter(explode("\n", file_get_contents($this->tmpFile) ?: ''));
        self::assertCount(1, $lines);
        self::assertCount(0, $exporter->buffer());
    }

    private function createSnapshot(string $name = 'test_metric'): MetricSnapshot
    {
        return new MetricSnapshot(
            name: $name,
            type: MetricType::Gauge,
            help: 'Test metric',
            values: ['default' => 1.0],
        );
    }
}
