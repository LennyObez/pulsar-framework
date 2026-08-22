<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Export\Otlp\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\AttributeEncoder;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\LogsRequestBuilder;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\OtlpFieldNumbers;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\OtlpLogRecord;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ProtobufWriter;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\ResourceInfo;

use function hex2bin;
use function strlen;

#[CoversClass(LogsRequestBuilder::class)]
#[CoversClass(AttributeEncoder::class)]
#[CoversClass(OtlpFieldNumbers::class)]
#[CoversClass(ProtobufWriter::class)]
#[CoversClass(OtlpLogRecord::class)]
#[CoversClass(ResourceInfo::class)]
final class LogsRequestBuilderTest extends TestCase
{
    #[Test]
    public function buildReturnsEmptyStringForNoLogRecords(): void
    {
        $builder = new LogsRequestBuilder();

        self::assertSame('', $builder->build([], new ResourceInfo()));
    }

    #[Test]
    public function buildProducesOutputForSingleLogRecord(): void
    {
        $builder = new LogsRequestBuilder();

        $record = new OtlpLogRecord(
            timeUnixNano: 1_700_000_000_000_000_000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'Something failed',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $binary = $builder->build([$record], new ResourceInfo());

        self::assertNotSame('', $binary);
        self::assertStringContainsString('Something failed', $binary);
        self::assertStringContainsString('ERROR', $binary);
    }

    #[Test]
    public function buildIncludesTraceCorrelation(): void
    {
        $builder = new LogsRequestBuilder();

        $traceId = (string) hex2bin('0af7651916cd43dd8448eb211c80319c');
        $spanId = (string) hex2bin('b7ad6b7169203331');

        $record = new OtlpLogRecord(
            timeUnixNano: 1_700_000_000_000_000_000,
            severityNumber: 9,
            severityText: 'INFO',
            body: 'Request handled',
            attributes: [],
            traceId: $traceId,
            spanId: $spanId,
        );

        $binary = $builder->build([$record], new ResourceInfo());

        // Binary trace/span IDs should appear in the output
        self::assertStringContainsString($traceId, $binary);
        self::assertStringContainsString($spanId, $binary);
    }

    #[Test]
    public function buildIncludesResourceAttributes(): void
    {
        $builder = new LogsRequestBuilder();

        $record = new OtlpLogRecord(
            timeUnixNano: 1_700_000_000_000_000_000,
            severityNumber: 9,
            severityText: 'INFO',
            body: 'test',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $resource = new ResourceInfo(['service.name' => 'log-service']);
        $binary = $builder->build([$record], $resource);

        self::assertStringContainsString('service.name', $binary);
        self::assertStringContainsString('log-service', $binary);
    }

    #[Test]
    public function buildIncludesLogAttributes(): void
    {
        $builder = new LogsRequestBuilder();

        $record = new OtlpLogRecord(
            timeUnixNano: 1_700_000_000_000_000_000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'Database error',
            attributes: ['db.system' => 'postgresql', 'db.statement' => 'SELECT 1'],
            traceId: null,
            spanId: null,
        );

        $binary = $builder->build([$record], new ResourceInfo());

        self::assertStringContainsString('db.system', $binary);
        self::assertStringContainsString('postgresql', $binary);
        self::assertStringContainsString('db.statement', $binary);
    }

    #[Test]
    public function buildIncludesScopeInformation(): void
    {
        $builder = new LogsRequestBuilder(scopeName: 'my-logger', scopeVersion: '1.5.0');

        $record = new OtlpLogRecord(
            timeUnixNano: 1_700_000_000_000_000_000,
            severityNumber: 9,
            severityText: 'INFO',
            body: 'test',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $binary = $builder->build([$record], new ResourceInfo());

        self::assertStringContainsString('my-logger', $binary);
        self::assertStringContainsString('1.5.0', $binary);
    }

    #[Test]
    public function buildHandlesMultipleLogRecords(): void
    {
        $builder = new LogsRequestBuilder();

        $record1 = new OtlpLogRecord(
            timeUnixNano: 1_700_000_000_000_000_000,
            severityNumber: 9,
            severityText: 'INFO',
            body: 'First log message',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $record2 = new OtlpLogRecord(
            timeUnixNano: 1_700_000_001_000_000_000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'Second log message',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $binary = $builder->build([$record1, $record2], new ResourceInfo());

        self::assertStringContainsString('First log message', $binary);
        self::assertStringContainsString('Second log message', $binary);
    }

    #[Test]
    public function buildHandlesDebugSeverity(): void
    {
        $builder = new LogsRequestBuilder();

        $record = new OtlpLogRecord(
            timeUnixNano: 1_700_000_000_000_000_000,
            severityNumber: 5,
            severityText: 'DEBUG',
            body: 'Debug details',
            attributes: ['component' => 'cache'],
            traceId: null,
            spanId: null,
        );

        $binary = $builder->build([$record], new ResourceInfo());

        self::assertNotSame('', $binary);
        self::assertStringContainsString('Debug details', $binary);
        self::assertStringContainsString('DEBUG', $binary);
    }

    #[Test]
    public function buildHandlesEmptySeverityText(): void
    {
        $builder = new LogsRequestBuilder();

        $record = new OtlpLogRecord(
            timeUnixNano: 1_700_000_000_000_000_000,
            severityNumber: 9,
            severityText: '',
            body: 'No severity text',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $binary = $builder->build([$record], new ResourceInfo());

        self::assertNotSame('', $binary);
        self::assertGreaterThan(0, strlen($binary));
    }

    #[Test]
    public function buildHandlesAllSeverityLevels(): void
    {
        $builder = new LogsRequestBuilder();

        $levels = [
            [5, 'DEBUG'],
            [9, 'INFO'],
            [10, 'INFO2'],
            [13, 'WARN'],
            [17, 'ERROR'],
            [21, 'FATAL'],
            [22, 'FATAL2'],
            [23, 'FATAL3'],
        ];

        foreach ($levels as [$severityNumber, $severityText]) {
            $record = new OtlpLogRecord(
                timeUnixNano: 1_700_000_000_000_000_000,
                severityNumber: $severityNumber,
                severityText: $severityText,
                body: "Log at $severityText",
                attributes: [],
                traceId: null,
                spanId: null,
            );

            $binary = $builder->build([$record], new ResourceInfo());

            self::assertNotSame('', $binary, "Failed for severity $severityText");
        }
    }
}
