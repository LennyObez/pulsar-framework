<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpFieldNumbers;

#[CoversClass(OtlpFieldNumbers::class)]
final class OtlpFieldNumbersTest extends TestCase
{
    #[Test]
    public function spanFieldNumbersAreCorrect(): void
    {
        self::assertSame(1, OtlpFieldNumbers::SPAN_TRACE_ID);
        self::assertSame(2, OtlpFieldNumbers::SPAN_SPAN_ID);
        self::assertSame(4, OtlpFieldNumbers::SPAN_PARENT_SPAN_ID);
        self::assertSame(5, OtlpFieldNumbers::SPAN_NAME);
        self::assertSame(6, OtlpFieldNumbers::SPAN_KIND);
        self::assertSame(7, OtlpFieldNumbers::SPAN_START_TIME);
        self::assertSame(8, OtlpFieldNumbers::SPAN_END_TIME);
        self::assertSame(9, OtlpFieldNumbers::SPAN_ATTRIBUTES);
        self::assertSame(15, OtlpFieldNumbers::SPAN_STATUS);
    }

    #[Test]
    public function statusFieldNumbersAreCorrect(): void
    {
        self::assertSame(2, OtlpFieldNumbers::STATUS_MESSAGE);
        self::assertSame(3, OtlpFieldNumbers::STATUS_CODE);
    }

    #[Test]
    public function spanKindConstants(): void
    {
        self::assertSame(1, OtlpFieldNumbers::SPAN_KIND_INTERNAL);
        self::assertSame(3, OtlpFieldNumbers::SPAN_KIND_CLIENT);
        self::assertSame(4, OtlpFieldNumbers::SPAN_KIND_PRODUCER);
        self::assertSame(5, OtlpFieldNumbers::SPAN_KIND_CONSUMER);
    }

    #[Test]
    public function statusCodeConstants(): void
    {
        self::assertSame(0, OtlpFieldNumbers::STATUS_CODE_UNSET);
        self::assertSame(1, OtlpFieldNumbers::STATUS_CODE_OK);
        self::assertSame(2, OtlpFieldNumbers::STATUS_CODE_ERROR);
    }

    #[Test]
    public function logRecordFieldNumbers(): void
    {
        self::assertSame(1, OtlpFieldNumbers::LR_TIME_UNIX_NANO);
        self::assertSame(2, OtlpFieldNumbers::LR_SEVERITY_NUMBER);
        self::assertSame(5, OtlpFieldNumbers::LR_BODY);
        self::assertSame(6, OtlpFieldNumbers::LR_ATTRIBUTES);
        self::assertSame(9, OtlpFieldNumbers::LR_TRACE_ID);
        self::assertSame(10, OtlpFieldNumbers::LR_SPAN_ID);
        self::assertSame(3, OtlpFieldNumbers::LR_SEVERITY_TEXT);
    }

    #[Test]
    public function metricFieldNumbers(): void
    {
        self::assertSame(1, OtlpFieldNumbers::METRIC_NAME);
        self::assertSame(2, OtlpFieldNumbers::METRIC_DESCRIPTION);
        self::assertSame(3, OtlpFieldNumbers::METRIC_UNIT);
        self::assertSame(5, OtlpFieldNumbers::METRIC_GAUGE);
        self::assertSame(7, OtlpFieldNumbers::METRIC_SUM);
        self::assertSame(9, OtlpFieldNumbers::METRIC_HISTOGRAM);
    }

    #[Test]
    public function aggregationTemporalityCumulative(): void
    {
        self::assertSame(2, OtlpFieldNumbers::AGGREGATION_TEMPORALITY_CUMULATIVE);
    }
}
