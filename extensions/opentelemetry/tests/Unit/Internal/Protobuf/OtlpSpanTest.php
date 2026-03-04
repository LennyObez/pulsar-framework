<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpFieldNumbers;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpSpan;

#[CoversClass(OtlpSpan::class)]
final class OtlpSpanTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $span = new OtlpSpan(
            traceId: str_repeat("\x01", 16),
            spanId: str_repeat("\x02", 8),
            parentSpanId: str_repeat("\x03", 8),
            name: 'GET /api',
            startTimeUnixNano: 1000000000,
            endTimeUnixNano: 2000000000,
            attributes: ['http.method' => 'GET'],
            statusCode: 1,
            statusMessage: '',
            kind: OtlpFieldNumbers::SPAN_KIND_CLIENT,
        );

        self::assertSame(str_repeat("\x01", 16), $span->traceId);
        self::assertSame(str_repeat("\x02", 8), $span->spanId);
        self::assertSame(str_repeat("\x03", 8), $span->parentSpanId);
        self::assertSame('GET /api', $span->name);
        self::assertSame(1000000000, $span->startTimeUnixNano);
        self::assertSame(2000000000, $span->endTimeUnixNano);
        self::assertSame(['http.method' => 'GET'], $span->attributes);
        self::assertSame(1, $span->statusCode);
        self::assertSame('', $span->statusMessage);
        self::assertSame(OtlpFieldNumbers::SPAN_KIND_CLIENT, $span->kind);
    }

    #[Test]
    public function defaultKindIsInternal(): void
    {
        $span = new OtlpSpan(
            traceId: str_repeat("\x00", 16),
            spanId: str_repeat("\x00", 8),
            parentSpanId: null,
            name: 'internal',
            startTimeUnixNano: 0,
            endTimeUnixNano: 0,
            attributes: [],
            statusCode: 0,
            statusMessage: '',
        );

        self::assertSame(OtlpFieldNumbers::SPAN_KIND_INTERNAL, $span->kind);
    }

    #[Test]
    public function nullParentSpanId(): void
    {
        $span = new OtlpSpan(
            traceId: str_repeat("\x00", 16),
            spanId: str_repeat("\x00", 8),
            parentSpanId: null,
            name: 'root',
            startTimeUnixNano: 0,
            endTimeUnixNano: 0,
            attributes: [],
            statusCode: 0,
            statusMessage: '',
        );

        self::assertNull($span->parentSpanId);
    }
}
