<?php

declare(strict_types=1);

namespace Pulsar\Extension\ObservabilityExport\Tests\Unit\Error;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\ObservabilityExport\Error\JsonLinesErrorExporter;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\ErrorTracking\ErrorFingerprint;

final class JsonLinesErrorExporterTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'pulsar_error_') ?: '/tmp/pulsar_error_test';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    #[Test]
    public function exportBuffersEvents(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tmpFile, flushThreshold: 5);
        $exporter->export($this->createEvent());

        self::assertCount(1, $exporter->buffer());
    }

    #[Test]
    public function exportFlushesWhenThresholdReached(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tmpFile, flushThreshold: 2);
        $exporter->export($this->createEvent());
        $exporter->export($this->createEvent());

        self::assertCount(0, $exporter->buffer());
        self::assertFileExists($this->tmpFile);
        $contents = file_get_contents($this->tmpFile);
        self::assertNotFalse($contents);
        self::assertNotEmpty(trim($contents));
    }

    #[Test]
    public function flushWritesBufferedEventsToFile(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tmpFile, flushThreshold: 100);
        $exporter->export($this->createEvent());
        $exporter->export($this->createEvent());
        $exporter->flush();

        self::assertCount(0, $exporter->buffer());
        $lines = array_filter(explode("\n", file_get_contents($this->tmpFile) ?: ''));
        self::assertCount(2, $lines);
    }

    #[Test]
    public function flushDoesNothingWhenBufferEmpty(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tmpFile, flushThreshold: 100);
        $exporter->flush();

        self::assertSame('', file_get_contents($this->tmpFile) ?: '');
    }

    #[Test]
    public function shutdownFlushesRemainingEvents(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tmpFile, flushThreshold: 100);
        $exporter->export($this->createEvent());
        $exporter->shutdown();

        self::assertCount(0, $exporter->buffer());
        $contents = file_get_contents($this->tmpFile);
        self::assertNotFalse($contents);
        self::assertNotEmpty(trim($contents));
    }

    #[Test]
    public function exportIgnoresEventsAfterShutdown(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tmpFile, flushThreshold: 100);
        $exporter->shutdown();
        $exporter->export($this->createEvent());

        self::assertCount(0, $exporter->buffer());
    }

    #[Test]
    public function shutdownIsIdempotent(): void
    {
        $exporter = new JsonLinesErrorExporter($this->tmpFile, flushThreshold: 100);
        $exporter->export($this->createEvent());
        $exporter->shutdown();
        $exporter->shutdown();

        $lines = array_filter(explode("\n", file_get_contents($this->tmpFile) ?: ''));
        self::assertCount(1, $lines);
    }

    private function createEvent(): ErrorEvent
    {
        return new ErrorEvent(
            fingerprint: new ErrorFingerprint('fp123'),
            exceptionClass: 'RuntimeException',
            message: 'Test error',
            file: '/app/Test.php',
            line: 10,
            stackTrace: [],
            context: [],
            occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }
}
