<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\ObservabilityExport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\ObservabilityExport\Metrics\JsonLinesMetricsExporter;
use Pulsar\Extension\ObservabilityExport\Schema\MetricSchema;
use Pulsar\Observability\Metrics\MetricSnapshot;
use Pulsar\Observability\Metrics\MetricType;

use function file_get_contents;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

#[CoversClass(JsonLinesMetricsExporter::class)]
#[CoversClass(MetricSchema::class)]
final class JsonLinesMetricsExporterTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar_metric_');

        if ($path === false) {
            self::fail('Failed to create temporary file');
        }

        $this->tempFile = $path;
    }

    protected function tearDown(): void
    {
        @unlink($this->tempFile);
    }

    #[Test]
    public function exportBuffersUntilThreshold(): void
    {
        $exporter = new JsonLinesMetricsExporter($this->tempFile, flushThreshold: 3);

        $exporter->export($this->createCounterSnapshot('req_count', 42.0));
        $exporter->export($this->createCounterSnapshot('err_count', 3.0));

        self::assertSame('', file_get_contents($this->tempFile));
        self::assertCount(2, $exporter->buffer());
    }

    #[Test]
    public function exportFlushesAtThreshold(): void
    {
        $exporter = new JsonLinesMetricsExporter($this->tempFile, flushThreshold: 2);

        $exporter->export($this->createCounterSnapshot('req_count', 42.0));
        $exporter->export($this->createGaugeSnapshot('active_conns', 10.0));

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(2, $lines);
        self::assertSame('req_count', $lines[0]['name']);
        self::assertSame('counter', $lines[0]['type']);
        self::assertSame('active_conns', $lines[1]['name']);
        self::assertSame('gauge', $lines[1]['type']);
        self::assertSame([], $exporter->buffer());
    }

    #[Test]
    public function flushWritesBufferedSnapshots(): void
    {
        $exporter = new JsonLinesMetricsExporter($this->tempFile, flushThreshold: 100);

        $exporter->export($this->createCounterSnapshot('req_count', 42.0));
        $exporter->flush();

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);
        self::assertSame('1.0.0', $lines[0]['schema_version']);
        self::assertSame('req_count', $lines[0]['name']);
    }

    #[Test]
    public function flushOnEmptyBufferIsNoOp(): void
    {
        $exporter = new JsonLinesMetricsExporter($this->tempFile, flushThreshold: 100);

        $exporter->flush();

        self::assertSame('', file_get_contents($this->tempFile));
    }

    #[Test]
    public function shutdownFlushesAndStopsAccepting(): void
    {
        $exporter = new JsonLinesMetricsExporter($this->tempFile, flushThreshold: 100);

        $exporter->export($this->createCounterSnapshot('req_count', 42.0));
        $exporter->shutdown();

        $exporter->export($this->createCounterSnapshot('ignored', 0.0));

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);
        self::assertSame('req_count', $lines[0]['name']);
    }

    #[Test]
    public function shutdownIsIdempotent(): void
    {
        $exporter = new JsonLinesMetricsExporter($this->tempFile, flushThreshold: 100);

        $exporter->export($this->createCounterSnapshot('req_count', 42.0));
        $exporter->shutdown();
        $exporter->shutdown();

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);
    }

    #[Test]
    public function schemaIncludesCounterFields(): void
    {
        $exporter = new JsonLinesMetricsExporter($this->tempFile, flushThreshold: 1);

        $exporter->export($this->createCounterSnapshot('http_requests_total', 100.0));

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);

        $data = $lines[0];

        self::assertSame('1.0.0', $data['schema_version']);
        self::assertSame('http_requests_total', $data['name']);
        self::assertSame('counter', $data['type']);
        self::assertSame('Total HTTP requests', $data['help']);
        self::assertArrayHasKey('values', $data);
        self::assertArrayNotHasKey('series', $data);
    }

    #[Test]
    public function schemaIncludesHistogramSeries(): void
    {
        $exporter = new JsonLinesMetricsExporter($this->tempFile, flushThreshold: 1);

        $snapshot = new MetricSnapshot(
            name: 'request_duration',
            type: MetricType::Histogram,
            help: 'Request duration in seconds',
            series: [
                '' => [
                    'buckets' => ['0.1' => 5, '0.5' => 8, '1' => 10],
                    'sum' => 4.5,
                    'count' => 10,
                ],
            ],
        );

        $exporter->export($snapshot);

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);

        $data = $lines[0];

        self::assertSame('histogram', $data['type']);
        self::assertArrayHasKey('series', $data);
        /** @var array<string, mixed> $series */
        $series = $data['series'];
        self::assertArrayHasKey('', $series);
    }

    private function createCounterSnapshot(string $name, float $value): MetricSnapshot
    {
        return new MetricSnapshot(
            name: $name,
            type: MetricType::Counter,
            help: 'Total HTTP requests',
            values: ['' => $value],
        );
    }

    private function createGaugeSnapshot(string $name, float $value): MetricSnapshot
    {
        return new MetricSnapshot(
            name: $name,
            type: MetricType::Gauge,
            help: 'Active connections',
            values: ['' => $value],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseJsonLines(string $content): array
    {
        $lines = [];
        $trimmed = rtrim($content, PHP_EOL);

        if ($trimmed === '') {
            return [];
        }

        foreach (explode(PHP_EOL, $trimmed) as $line) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $lines[] = $decoded;
        }

        return $lines;
    }
}
