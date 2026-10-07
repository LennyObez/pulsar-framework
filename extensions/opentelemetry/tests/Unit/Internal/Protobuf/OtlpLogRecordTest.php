<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpLogRecord;

#[CoversClass(OtlpLogRecord::class)]
final class OtlpLogRecordTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $traceId = str_repeat("\x01", 16);
        $spanId = str_repeat("\x02", 8);

        $record = new OtlpLogRecord(
            timeUnixNano: 1000000000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'Something failed',
            attributes: ['error.code' => 42],
            traceId: $traceId,
            spanId: $spanId,
        );

        self::assertSame(1000000000, $record->timeUnixNano);
        self::assertSame(17, $record->severityNumber);
        self::assertSame('ERROR', $record->severityText);
        self::assertSame('Something failed', $record->body);
        self::assertSame(['error.code' => 42], $record->attributes);
        self::assertSame($traceId, $record->traceId);
        self::assertSame($spanId, $record->spanId);
    }

    #[Test]
    public function nullTraceAndSpanIds(): void
    {
        $record = new OtlpLogRecord(
            timeUnixNano: 0,
            severityNumber: 5,
            severityText: 'DEBUG',
            body: 'debug message',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        self::assertNull($record->traceId);
        self::assertNull($record->spanId);
    }
}
