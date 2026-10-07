<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExportTests\Unit\Span;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\ObservabilityExport\Span\JsonLinesSpanExporter;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

final class JsonLinesSpanExporterTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_spans_') ?: '/tmp/pulsar_spans_test';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    #[Test]
    public function exportBuffersSpans(): void
    {
        $exporter = new JsonLinesSpanExporter($this->tmpFile, flushThreshold: 5);
        $exporter->export($this->createSpan());

        self::assertCount(1, $exporter->buffer());
    }

    #[Test]
    public function exportFlushesAtThreshold(): void
    {
        $exporter = new JsonLinesSpanExporter($this->tmpFile, flushThreshold: 2);
        $exporter->export($this->createSpan());
        $exporter->export($this->createSpan());

        self::assertCount(0, $exporter->buffer());
    }

    #[Test]
    public function flushWritesJsonLinesFormat(): void
    {
        $exporter = new JsonLinesSpanExporter($this->tmpFile, flushThreshold: 100);
        $span = $this->createSpan();
        $span->end();
        $exporter->export($span);
        $exporter->flush();

        $content = file_get_contents($this->tmpFile);
        self::assertNotFalse($content);
        $lines = array_filter(explode("\n", $content));
        self::assertCount(1, $lines);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('test.span', $decoded['name']);
        self::assertSame('1.0.0', $decoded['schema_version']);
    }

    #[Test]
    public function shutdownFlushesAndPreventsSubsequentExports(): void
    {
        $exporter = new JsonLinesSpanExporter($this->tmpFile, flushThreshold: 100);
        $exporter->export($this->createSpan());
        $exporter->shutdown();
        $exporter->export($this->createSpan());

        self::assertCount(0, $exporter->buffer());
        $lines = array_filter(explode("\n", file_get_contents($this->tmpFile) ?: ''));
        self::assertCount(1, $lines);
    }

    private function createSpan(): Span
    {
        $context = new TraceContext(
            traceId: new TraceId('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'),
            spanId: new SpanId('1234567890abcdef'),
        );

        return new Span(name: 'test.span', context: $context);
    }
}
