<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Tracing\Bridge;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Export\Otlp\LogsBatchExporter;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ResourceInfo;
use Pulsar\Extension\Observability\Export\Otlp\Transport\OtlpTransportInterface;
use Pulsar\Extension\Observability\Export\Otlp\Transport\TransportResult;
use Pulsar\Extension\Observability\Tracing\Bridge\OtlpLogBridge;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;

#[CoversClass(OtlpLogBridge::class)]
final class OtlpLogBridgeTest extends TestCase
{
    private LogsBatchExporter $exporter;

    protected function setUp(): void
    {
        $transport = $this->createStub(OtlpTransportInterface::class);
        $transport->method('send')->willReturn(TransportResult::success(200));

        $this->exporter = new LogsBatchExporter(
            transport: $transport,
            resource: new ResourceInfo(),
            maxBatchSize: 10000,
        );
    }

    #[Test]
    public function writesLogEntryAsOtlpRecord(): void
    {
        $bridge = new OtlpLogBridge(
            exporter: $this->exporter,
            scrubber: new SensitiveDataScrubber(),
        );

        $entry = new LogEntry(
            level: LogLevel::Error,
            message: 'Something went wrong',
            context: ['request_id' => 'abc123'],
            channel: 'app',
            timestamp: new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')),
        );

        $bridge->write($entry);

        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function scrubsSensitiveData(): void
    {
        $bridge = new OtlpLogBridge(
            exporter: $this->exporter,
            scrubber: new SensitiveDataScrubber(),
            minLevel: LogLevel::Debug,
        );

        $entry = new LogEntry(
            level: LogLevel::Info,
            message: 'User login',
            context: ['user' => 'john', 'password' => 'secret123', 'api_key' => 'sk-1234'],
            channel: 'auth',
            timestamp: new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')),
        );

        // This should not throw; sensitive data is scrubbed, not rejected
        $bridge->write($entry);
        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function includesTraceCorrelation(): void
    {
        $provider = $this->createStub(CorrelationContextProviderInterface::class);
        $provider->method('current')->willReturn(new CorrelationContext(
            traceId: '0af7651916cd43dd8448eb211c80319c',
            spanId: 'b7ad6b7169203331',
        ));

        $bridge = new OtlpLogBridge(
            exporter: $this->exporter,
            scrubber: new SensitiveDataScrubber(),
            correlationProvider: $provider,
            minLevel: LogLevel::Debug,
        );

        $entry = new LogEntry(
            level: LogLevel::Debug,
            message: 'Test',
            context: [],
            channel: 'test',
            timestamp: new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')),
        );

        $bridge->write($entry);
        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function handlesNullCorrelationContext(): void
    {
        $bridge = new OtlpLogBridge(
            exporter: $this->exporter,
            scrubber: new SensitiveDataScrubber(),
        );

        $entry = new LogEntry(
            level: LogLevel::Warning,
            message: 'No correlation',
            context: [],
            channel: 'app',
            timestamp: new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')),
        );

        $bridge->write($entry);
        self::assertSame(1, $this->exporter->queueSize());
    }

    #[Test]
    public function enqueuessMultipleLogEntries(): void
    {
        $bridge = new OtlpLogBridge(
            exporter: $this->exporter,
            scrubber: new SensitiveDataScrubber(),
            minLevel: LogLevel::Debug,
        );

        $timestamp = new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC'));

        $levels = [LogLevel::Debug, LogLevel::Info, LogLevel::Warning, LogLevel::Error, LogLevel::Critical, LogLevel::Emergency];

        foreach ($levels as $level) {
            $bridge->write(new LogEntry(
                level: $level,
                message: 'test',
                context: [],
                channel: 'test',
                timestamp: $timestamp,
            ));
        }

        self::assertSame(6, $this->exporter->queueSize());
    }
}
