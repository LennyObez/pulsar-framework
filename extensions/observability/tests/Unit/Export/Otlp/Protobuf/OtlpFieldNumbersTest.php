<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Export\Otlp\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Export\Otlp\Protobuf\OtlpFieldNumbers;

use function constant;

#[CoversClass(OtlpFieldNumbers::class)]
final class OtlpFieldNumbersTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function traceFieldProvider(): iterable
    {
        yield 'RESOURCE_SPANS' => ['RESOURCE_SPANS', 1];
        yield 'RS_RESOURCE' => ['RS_RESOURCE', 1];
        yield 'RS_SCOPE_SPANS' => ['RS_SCOPE_SPANS', 2];
        yield 'SPAN_TRACE_ID' => ['SPAN_TRACE_ID', 1];
        yield 'SPAN_SPAN_ID' => ['SPAN_SPAN_ID', 2];
        yield 'SPAN_NAME' => ['SPAN_NAME', 5];
        yield 'SPAN_KIND' => ['SPAN_KIND', 6];
        yield 'SPAN_START_TIME' => ['SPAN_START_TIME', 7];
        yield 'SPAN_END_TIME' => ['SPAN_END_TIME', 8];
        yield 'SPAN_ATTRIBUTES' => ['SPAN_ATTRIBUTES', 9];
        yield 'SPAN_STATUS' => ['SPAN_STATUS', 15];
    }

    #[Test]
    #[DataProvider('traceFieldProvider')]
    public function traceFieldNumbersMatchProtoV131(string $constant, int $expected): void
    {
        self::assertSame($expected, constant(OtlpFieldNumbers::class . '::' . $constant));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function metricFieldProvider(): iterable
    {
        yield 'RESOURCE_METRICS' => ['RESOURCE_METRICS', 1];
        yield 'METRIC_NAME' => ['METRIC_NAME', 1];
        yield 'METRIC_DESCRIPTION' => ['METRIC_DESCRIPTION', 2];
        yield 'METRIC_UNIT' => ['METRIC_UNIT', 3];
        yield 'METRIC_GAUGE' => ['METRIC_GAUGE', 5];
        yield 'METRIC_SUM' => ['METRIC_SUM', 7];
        yield 'METRIC_HISTOGRAM' => ['METRIC_HISTOGRAM', 9];
        yield 'SUM_IS_MONOTONIC' => ['SUM_IS_MONOTONIC', 3];
    }

    #[Test]
    #[DataProvider('metricFieldProvider')]
    public function metricFieldNumbersMatchProtoV131(string $constant, int $expected): void
    {
        self::assertSame($expected, constant(OtlpFieldNumbers::class . '::' . $constant));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function logFieldProvider(): iterable
    {
        yield 'RESOURCE_LOGS' => ['RESOURCE_LOGS', 1];
        yield 'LR_TIME_UNIX_NANO' => ['LR_TIME_UNIX_NANO', 1];
        yield 'LR_SEVERITY_NUMBER' => ['LR_SEVERITY_NUMBER', 2];
        yield 'LR_BODY' => ['LR_BODY', 5];
        yield 'LR_ATTRIBUTES' => ['LR_ATTRIBUTES', 6];
        yield 'LR_TRACE_ID' => ['LR_TRACE_ID', 9];
        yield 'LR_SPAN_ID' => ['LR_SPAN_ID', 10];
    }

    #[Test]
    #[DataProvider('logFieldProvider')]
    public function logFieldNumbersMatchProtoV131(string $constant, int $expected): void
    {
        self::assertSame($expected, constant(OtlpFieldNumbers::class . '::' . $constant));
    }

    #[Test]
    public function spanKindConstantsMatchOtlpSpec(): void
    {
        self::assertSame(1, OtlpFieldNumbers::SPAN_KIND_INTERNAL);
        self::assertSame(3, OtlpFieldNumbers::SPAN_KIND_CLIENT);
        self::assertSame(4, OtlpFieldNumbers::SPAN_KIND_PRODUCER);
        self::assertSame(5, OtlpFieldNumbers::SPAN_KIND_CONSUMER);
    }

    #[Test]
    public function statusCodeConstantsMatchOtlpSpec(): void
    {
        self::assertSame(0, OtlpFieldNumbers::STATUS_CODE_UNSET);
        self::assertSame(1, OtlpFieldNumbers::STATUS_CODE_OK);
        self::assertSame(2, OtlpFieldNumbers::STATUS_CODE_ERROR);
    }

    #[Test]
    public function aggregationTemporalityCumulativeIs2(): void
    {
        self::assertSame(2, OtlpFieldNumbers::AGGREGATION_TEMPORALITY_CUMULATIVE);
    }
}
