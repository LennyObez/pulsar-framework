<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\ObservabilityExport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\ObservabilityExport\Schema\SpanSchema;
use Pulsar\Extension\ObservabilityExport\Span\JsonLinesSpanExporter;
use Pulsar\Observability\Tracing\Span;
use Pulsar\Observability\Tracing\SpanId;
use Pulsar\Observability\Tracing\SpanStatus;
use Pulsar\Observability\Tracing\TraceContext;
use Pulsar\Observability\Tracing\TraceId;

use function file_get_contents;
use function json_decode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

#[CoversClass(JsonLinesSpanExporter::class)]
#[CoversClass(SpanSchema::class)]
final class JsonLinesSpanExporterTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar_span_');

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
        $exporter = new JsonLinesSpanExporter($this->tempFile, flushThreshold: 3);

        $exporter->export($this->createSpan('op1'));
        $exporter->export($this->createSpan('op2'));

        // Below threshold — file should be empty
        self::assertSame('', file_get_contents($this->tempFile));
        self::assertCount(2, $exporter->buffer());
    }

    #[Test]
    public function exportFlushesAtThreshold(): void
    {
        $exporter = new JsonLinesSpanExporter($this->tempFile, flushThreshold: 2);

        $exporter->export($this->createSpan('op1'));
        $exporter->export($this->createSpan('op2'));

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(2, $lines);
        self::assertSame('op1', $lines[0]['name']);
        self::assertSame('op2', $lines[1]['name']);
        self::assertSame([], $exporter->buffer());
    }

    #[Test]
    public function flushWritesBufferedSpans(): void
    {
        $exporter = new JsonLinesSpanExporter($this->tempFile, flushThreshold: 100);

        $exporter->export($this->createSpan('op1'));
        $exporter->flush();

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);
        self::assertSame('1.0.0', $lines[0]['schema_version']);
        self::assertSame('op1', $lines[0]['name']);
    }

    #[Test]
    public function flushOnEmptyBufferIsNoOp(): void
    {
        $exporter = new JsonLinesSpanExporter($this->tempFile, flushThreshold: 100);

        $exporter->flush();

        self::assertSame('', file_get_contents($this->tempFile));
    }

    #[Test]
    public function shutdownFlushesAndStopsAccepting(): void
    {
        $exporter = new JsonLinesSpanExporter($this->tempFile, flushThreshold: 100);

        $exporter->export($this->createSpan('op1'));
        $exporter->shutdown();

        // After shutdown, new exports are ignored
        $exporter->export($this->createSpan('op2'));

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);
        self::assertSame('op1', $lines[0]['name']);
    }

    #[Test]
    public function shutdownIsIdempotent(): void
    {
        $exporter = new JsonLinesSpanExporter($this->tempFile, flushThreshold: 100);

        $exporter->export($this->createSpan('op1'));
        $exporter->shutdown();
        $exporter->shutdown();

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);
    }

    #[Test]
    public function schemaIncludesAllSpanFields(): void
    {
        $exporter = new JsonLinesSpanExporter($this->tempFile, flushThreshold: 1);

        $span = $this->createSpan('test-operation');
        $span->setAttribute('http.method', 'GET');
        $span->setAttribute('http.status_code', 200);
        $span->status = SpanStatus::Ok;
        $span->end();

        $exporter->export($span);

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);

        $data = $lines[0];

        self::assertSame('1.0.0', $data['schema_version']);
        self::assertArrayHasKey('trace_id', $data);
        self::assertArrayHasKey('span_id', $data);
        self::assertArrayHasKey('parent_span_id', $data);
        self::assertSame('test-operation', $data['name']);
        self::assertSame('ok', $data['status']);
        self::assertIsInt($data['start_time_ns']);
        self::assertIsInt($data['end_time_ns']);
        self::assertIsInt($data['duration_ns']);
        /** @var array<string, mixed> $attributes */
        $attributes = $data['attributes'];
        self::assertSame('GET', $attributes['http.method']);
        self::assertSame(200, $attributes['http.status_code']);
    }

    private function createSpan(string $name): Span
    {
        $context = new TraceContext(
            new TraceId('0af7651916cd43dd8448eb211c80319c'),
            new SpanId('b7ad6b7169203331'),
        );

        return new Span($name, $context);
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
