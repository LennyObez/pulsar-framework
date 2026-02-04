<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\ObservabilityExport;

use DateTimeImmutable;
use DateTimeZone;

use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\ObservabilityExport\Error\JsonLinesErrorExporter;
use Pulsar\Extension\ObservabilityExport\Schema\ErrorSchema;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\ErrorTracking\ErrorFingerprint;
use Pulsar\Observability\Tracing\TraceId;

use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(JsonLinesErrorExporter::class)]
#[CoversClass(ErrorSchema::class)]
final class JsonLinesErrorExporterTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsar_error_');

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
        $exporter = new JsonLinesErrorExporter($this->tempFile, flushThreshold: 3);

        $exporter->export($this->createErrorEvent('Error one'));
        $exporter->export($this->createErrorEvent('Error two'));

        self::assertSame('', file_get_contents($this->tempFile));
        self::assertCount(2, $exporter->buffer());
    }

    #[Test]
    public function exportFlushesAtThreshold(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tempFile, flushThreshold: 2);

        $exporter->export($this->createErrorEvent('Error one'));
        $exporter->export($this->createErrorEvent('Error two'));

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(2, $lines);
        self::assertSame('Error one', $lines[0]['message']);
        self::assertSame('Error two', $lines[1]['message']);
        self::assertSame([], $exporter->buffer());
    }

    #[Test]
    public function flushWritesBufferedEvents(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tempFile, flushThreshold: 100);

        $exporter->export($this->createErrorEvent('Error one'));
        $exporter->flush();

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);
        self::assertSame('1.0.0', $lines[0]['schema_version']);
        self::assertSame('Error one', $lines[0]['message']);
    }

    #[Test]
    public function flushOnEmptyBufferIsNoOp(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tempFile, flushThreshold: 100);

        $exporter->flush();

        self::assertSame('', file_get_contents($this->tempFile));
    }

    #[Test]
    public function shutdownFlushesAndStopsAccepting(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tempFile, flushThreshold: 100);

        $exporter->export($this->createErrorEvent('Error one'));
        $exporter->shutdown();

        $exporter->export($this->createErrorEvent('Ignored'));

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);
        self::assertSame('Error one', $lines[0]['message']);
    }

    #[Test]
    public function shutdownIsIdempotent(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tempFile, flushThreshold: 100);

        $exporter->export($this->createErrorEvent('Error one'));
        $exporter->shutdown();
        $exporter->shutdown();

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);
    }

    #[Test]
    public function schemaIncludesAllErrorFields(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tempFile, flushThreshold: 1);

        $traceId = new TraceId('0af7651916cd43dd8448eb211c80319c');

        $event = new ErrorEvent(
            fingerprint: new ErrorFingerprint('abc123'),
            exceptionClass: 'RuntimeException',
            message: 'Something failed',
            file: '/app/src/Handler.php',
            line: 42,
            stackTrace: [
                [
                    'file' => '/app/src/Handler.php',
                    'line' => 42,
                    'class' => 'App\\Handler',
                    'function' => 'handle',
                ],
            ],
            context: ['request_id' => 'req-001'],
            occurredAt: new DateTimeImmutable('2025-01-15T10:30:00.000000+00:00', new DateTimeZone('UTC')),
            traceId: $traceId,
        );

        $exporter->export($event);

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);

        $data = $lines[0];

        self::assertSame('1.0.0', $data['schema_version']);
        self::assertSame('abc123', $data['fingerprint']);
        self::assertSame('RuntimeException', $data['exception_class']);
        self::assertSame('Something failed', $data['message']);
        self::assertSame('/app/src/Handler.php', $data['file']);
        self::assertSame(42, $data['line']);
        /** @var list<mixed> $stackTrace */
        $stackTrace = $data['stack_trace'];
        self::assertCount(1, $stackTrace);
        /** @var array<string, mixed> $context */
        $context = $data['context'];
        self::assertSame('req-001', $context['request_id']);
        /** @var string $occurredAt */
        $occurredAt = $data['occurred_at'];
        self::assertStringContainsString('2025-01-15', $occurredAt);
        self::assertSame('0af7651916cd43dd8448eb211c80319c', $data['trace_id']);
    }

    #[Test]
    public function schemaHandlesNullTraceId(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tempFile, flushThreshold: 1);

        $exporter->export($this->createErrorEvent('No trace'));

        $content = file_get_contents($this->tempFile);
        self::assertNotFalse($content);

        $lines = $this->parseJsonLines($content);

        self::assertCount(1, $lines);
        self::assertNull($lines[0]['trace_id']);
    }

    private function createErrorEvent(string $message): ErrorEvent
    {
        return new ErrorEvent(
            fingerprint: new ErrorFingerprint('fp_' . $message),
            exceptionClass: 'RuntimeException',
            message: $message,
            file: '/app/src/Service.php',
            line: 10,
            stackTrace: [],
            context: [],
            occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
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
